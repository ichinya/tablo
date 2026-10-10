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

    // One candidate/pass, including expiry or stale cancellation. Caller owns channel mutex.
    public function claim(int $now): array|false
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $settings=$this->one('SELECT * FROM notification_settings WHERE id = 1');
            if ($settings['blocked'] || !$settings['enabled'] || $settings['endpoint_cipher'] === null
                || !($settings['unavailable'] || $settings['recovery'] || $settings['version_lag'])) {
                $this->db->exec('COMMIT'); return false;
            }
            $slot=$this->one("SELECT * FROM notification_slots WHERE
                (status = 'pending' AND due_at <= ?) OR (status = 'inflight' AND lease_until <= ?)
                ORDER BY due_at,site_id,event LIMIT 1",[$now,$now]);
            if ($slot === false) { $this->db->exec('COMMIT'); return false; }
            $site=$this->one('SELECT enabled,config_revision FROM sites WHERE id = ?',[$slot['site_id']]);
            $status=null;
            if ($site === false || !$site['enabled'] || $site['config_revision'] !== $slot['config_revision']
                || $settings['revision'] !== $slot['channel_revision'] || !$settings[$slot['event']]) { $status='cancelled'; }
            elseif ($now >= $slot['expires_at']) { $status='expired'; }
            elseif ($slot['attempts'] >= 3) { $status='failed'; }
            if ($status !== null) {
                $this->execute('UPDATE notification_slots SET status=?,claim=NULL,lease_until=NULL WHERE site_id=? AND event=?',[$status,$slot['site_id'],$slot['event']]);
                $this->db->exec('COMMIT'); return false;
            }
            // A lost owner consumes its attempt and observes backoff after lease expiry.
            if ($slot['status'] === 'inflight') {
                $this->execute("UPDATE notification_slots SET status='pending',claim=NULL,lease_until=NULL,last_code='ambiguous',due_at=?
                    WHERE site_id=? AND event=?",[$now+($slot['attempts']===1?60:300),$slot['site_id'],$slot['event']]);
                $this->db->exec('COMMIT'); return false;
            }
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
