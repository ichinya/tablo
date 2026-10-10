<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class NotificationOutbox
{
    public function __construct(private readonly PDO $db) {}

    public function status(): array
    {
        $statement=$this->db->query('SELECT site_id,event,status,attempts,due_at,expires_at,last_code,http_status,acknowledged_at,coalesced
            FROM notification_slots ORDER BY site_id,event LIMIT 50');
        try { return $statement->fetchAll(); } finally { $statement->closeCursor(); }
    }

    // At most32 maintenance updates and one eligible claim/pass. Caller owns channel mutex.
    public function claim(int $now): array|false
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $settings=$this->one('SELECT * FROM notification_settings WHERE id = 1');
            if ($settings['blocked'] || !$settings['enabled'] || $settings['endpoint_cipher'] === null
                || !($settings['unavailable'] || $settings['recovery'] || $settings['version_lag'])) {
                $this->db->exec('COMMIT'); return false;
            }
            $this->maintain($settings,$now);
            // Invalid/expired rows cannot consume the fresh event's entire one-hour TTL.
            $slot=$this->one("SELECT n.* FROM notification_slots n INDEXED BY notification_eligible
                JOIN sites s ON s.id=n.site_id WHERE n.status='pending' AND n.channel_revision=? AND n.expires_at>?
                AND n.attempts<3 AND n.due_at<=? AND s.enabled=1 AND s.config_revision=n.config_revision
                AND ((n.event='unavailable' AND CAST(? AS INTEGER)=1) OR (n.event='recovery' AND CAST(? AS INTEGER)=1) OR (n.event='version_lag' AND CAST(? AS INTEGER)=1))
                ORDER BY n.due_at,n.site_id,n.event LIMIT 1",[$settings['revision'],$now,$now,
                    $settings['unavailable'],$settings['recovery'],$settings['version_lag']]);
            if ($slot === false) { $this->db->exec('COMMIT'); return false; }
            $claim=bin2hex(random_bytes(16));
            $this->execute("UPDATE notification_slots SET status='inflight',claim=?,lease_until=?,attempts=attempts+1
                WHERE site_id=? AND event=?",[$claim,$now+30,$slot['site_id'],$slot['event']]);
            $this->db->exec('COMMIT');
            return ['slot'=>$slot,'claim'=>$claim,'attempt'=>$slot['attempts']+1,'settings'=>$settings];
        } catch (\Throwable $error) {
            try { $this->db->exec('ROLLBACK'); } catch (\Throwable) { }
            throw $error;
        }
    }

    private function maintain(#[\SensitiveParameter] array $settings,int $now): void
    {
        $query=$this->db->prepare("SELECT n.*,s.enabled AS site_enabled,s.config_revision AS current_revision
            FROM notification_slots n LEFT JOIN sites s ON s.id=n.site_id WHERE
            (n.status='pending' AND n.due_at<=? AND (n.expires_at<=? OR n.attempts>=3 OR n.channel_revision<>?
                OR s.id IS NULL OR s.enabled<>1 OR s.config_revision<>n.config_revision
                OR (n.event='unavailable' AND CAST(? AS INTEGER)=0) OR (n.event='recovery' AND CAST(? AS INTEGER)=0) OR (n.event='version_lag' AND CAST(? AS INTEGER)=0)))
            OR (n.status='inflight' AND n.lease_until<=?) ORDER BY n.due_at,n.site_id,n.event LIMIT 32");
        try {
            $query->execute([$now,$now,$settings['revision'],$settings['unavailable'],$settings['recovery'],$settings['version_lag'],$now]);
            $rows=$query->fetchAll();
        } finally { $query->closeCursor(); }
        foreach($rows as $slot){
            $status=null;
            if (!$slot['site_enabled'] || $slot['current_revision']!==$slot['config_revision']
                || $settings['revision']!==$slot['channel_revision'] || !$settings[$slot['event']]) { $status='cancelled'; }
            elseif($now>=$slot['expires_at']){$status='expired';}
            elseif($slot['attempts']>=3){$status='failed';}
            if($status!==null){
                $this->execute('UPDATE notification_slots SET status=?,claim=NULL,lease_until=NULL WHERE site_id=? AND event=?',
                    [$status,$slot['site_id'],$slot['event']]);
            } else {
                // A lost owner consumes its attempt; no free retry after lease expiry.
                $this->execute("UPDATE notification_slots SET status='pending',claim=NULL,lease_until=NULL,last_code='ambiguous',due_at=?
                    WHERE site_id=? AND event=?",[$now+($slot['attempts']===1?60:300),$slot['site_id'],$slot['event']]);
            }
        }
    }

    public function acknowledge(#[\SensitiveParameter] array $claimed, array $result, int $now): bool
    {
        if (!in_array($result['code']??null,['sent','http','redirect','timeout','dns','ssrf','tls','refused','network','size',
            'invalid-url','invalid-data','invalid-response','ambiguous','child-start','child-frame'],true)
            || (isset($result['http_status']) && (!is_int($result['http_status']) || $result['http_status']<100 || $result['http_status']>599))) {
            throw new \InvalidArgumentException('Invalid notification result.');
        }
        if ($result['code']==='sent' && (!isset($result['http_status']) || $result['http_status']<200 || $result['http_status']>=300)) {
            throw new \InvalidArgumentException('Invalid notification result.');
        }
        $slot=$claimed['slot'];
        $success=$result['code']==='sent';
        $retry=in_array($result['code'],['timeout','dns','refused','network','ambiguous','child-start','child-frame'],true)
            || ($result['http_status'] ?? 0)===429 || ($result['http_status'] ?? 0)>=500;
        $status=$success?'sent':($retry && $claimed['attempt']<3 && $now<$slot['expires_at']?'pending':'failed');
        if ($now >= $slot['expires_at'] && !$success) { $status='expired'; }
        $query=$this->db->prepare("UPDATE notification_slots SET status=?,due_at=?,claim=NULL,lease_until=NULL,
            last_code=?,http_status=?,acknowledged_at=? WHERE site_id=? AND event=? AND event_id=? AND claim=? AND attempts=?
            AND status='inflight' AND channel_revision=? AND lease_until>? AND expires_at>? AND EXISTS(SELECT 1 FROM notification_settings
                WHERE id=1 AND revision=? AND enabled=1) AND EXISTS(SELECT 1 FROM sites WHERE id=? AND enabled=1 AND config_revision=?)");
        try {
            $query->execute([$status,$now+($claimed['attempt']===1?60:300),$result['code'],$result['http_status']??null,$success?$now:null,
                $slot['site_id'],$slot['event'],$slot['event_id'],$claimed['claim'],$claimed['attempt'],$slot['channel_revision'],
                $now,$now,
                $slot['channel_revision'],$slot['site_id'],$slot['config_revision']]);
            return $query->rowCount()===1;
        } finally { $query->closeCursor(); }
    }

    private function one(string $sql,array $values=[]): array|false
    {
        $query=$this->db->prepare($sql);
        try { $query->execute($values); return $query->fetch(); } finally { $query->closeCursor(); }
    }
    private function execute(string $sql,#[\SensitiveParameter] array $values): void
    {
        $query=$this->db->prepare($sql);
        try { $query->execute($values); } finally { $query->closeCursor(); }
    }
}
