<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class NotificationProjector
{
    public function __construct(private readonly PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault) {}

    // Called only within the genuine SiteRepository accepted-result transaction.
    public function accepted(int $historyId, array $row, #[\SensitiveParameter] array $site, int $wall): void
    {
        $settings = $this->one('SELECT installation_id, revision, activation_id, enabled, unavailable, recovery, version_lag,
            include_name, include_url, endpoint_cipher IS NOT NULL AS endpoint_present FROM notification_settings WHERE id = 1');
        $old = $this->one('SELECT * FROM notification_checkpoints WHERE site_id = ?', [$row['site_id']]);
        if ($old !== false && $old['last_history_id'] >= $historyId) { return; }
        $empty = ['channel'=> $settings['revision'], 'watermark'=>'', 'wall'=>0, 'health'=>null, 'health_since'=>null,
            'health_wall'=>null, 'health_count'=>0, 'health_handled'=>null, 'recovery_handled'=>null,
            'lag'=>null, 'lag_since'=>null, 'lag_wall'=>null, 'lag_count'=>0, 'lag_handled'=>null];
        $state = $old === false ? $empty : json_decode($old['state'],true,16,JSON_THROW_ON_ERROR);
        if ($old !== false && ($old['config_revision'] !== $row['config_revision'] || $state['channel'] !== $settings['revision'])) {
            $this->execute("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL
                WHERE site_id = ? AND status IN ('pending','inflight')",[$row['site_id']]);
            if ($old['config_revision'] !== $row['config_revision']) { $state = $empty; }
            else {
                // Reconfiguration cannot replay an unchanged handled logical transition.
                $state['channel']=$settings['revision'];
                $state['health_since']=$state['health_wall']=$state['lag_since']=$state['lag_wall']=null;
                $state['health_count']=$state['lag_count']=0;
            }
        }
        $late = $row['checked_at'] < $state['watermark'] || $wall < $state['wall'];
        $state['watermark'] = max($state['watermark'],$row['checked_at']);
        $state['wall'] = max($state['wall'],$wall);
        $enabled = $settings['enabled'] && $settings['endpoint_present']
            && ($settings['unavailable'] || $settings['recovery'] || $settings['version_lag']);
        if (!$enabled || $late) {
            $state['health_since'] = $state['health_wall'] = $state['lag_since'] = $state['lag_wall'] = null;
            $state['health_count'] = $state['lag_count'] = 0;
        } else {
            // Health identity and closure come exclusively from actual16, never another engine.
            $incident = $this->one('SELECT * FROM incidents WHERE site_id = ? AND (end_reason IS NULL OR recovery_history_id = ?)
                ORDER BY id DESC LIMIT 1',[$row['site_id'],$historyId]);
            if ($incident !== false && $incident['id'] > $settings['activation_id']) {
                if ($incident['end_reason'] === 'recovered') {
                    $slot = $this->one("SELECT source_id, attempts FROM notification_slots WHERE site_id = ? AND event = 'unavailable'",[$row['site_id']]);
                    $attempted = $slot !== false && $slot['source_id'] === $incident['id'] && $slot['attempts'] > 0;
                    $this->execute("UPDATE notification_slots SET status = 'cancelled', claim = NULL, lease_until = NULL
                        WHERE site_id = ? AND event = 'unavailable' AND source_id = ? AND attempts = 0 AND status = 'pending'",[$row['site_id'],$incident['id']]);
                    if ($settings['recovery'] && (!$settings['unavailable'] || $attempted)
                        && $state['recovery_handled'] !== $incident['id']) {
                        $this->enqueue('recovery',$incident['id'],$row,$site,$settings,$wall,null);
                    }
                    $state['recovery_handled'] = $incident['id'];
                    $state['health'] = $state['health_since'] = $state['health_wall'] = null;
                    $state['health_count'] = 0;
                } elseif ($row['online'] === 0) {
                    if ($state['health'] !== $incident['id']) {
                        $state['health'] = $incident['id']; $state['health_since'] = $state['health_wall'] = null; $state['health_count'] = 0;
                    }
                    if ($this->confirm($state,'health',$row['checked_at'],$wall) && $settings['unavailable']
                        && $state['health_handled'] !== $incident['id']) {
                        $this->enqueue('unavailable',$incident['id'],$row,$site,$settings,$wall,null);
                        $state['health_handled'] = $incident['id'];
                    }
                } else {
                    $state['health_since'] = $state['health_wall'] = null; $state['health_count'] = 0;
                }
            }
            $comparison = self::comparison($row,$site);
            if ($comparison === false) {
                $this->execute("UPDATE notification_slots SET status='cancelled',claim=NULL,lease_until=NULL
                    WHERE site_id=? AND event='version_lag' AND status IN ('pending','inflight')",[$row['site_id']]);
                $state['lag'] = $state['lag_since'] = $state['lag_wall'] = null; $state['lag_count'] = 0;
            } elseif ($comparison === null) {
                $state['lag_since'] = $state['lag_wall'] = null; $state['lag_count'] = 0;
            } elseif ($historyId > $settings['activation_id']) {
                $state['lag'] ??= $historyId;
                if ($this->confirm($state,'lag',$row['checked_at'],$wall) && $settings['version_lag']
                    && $state['lag'] > $settings['activation_id']
                    && $state['lag_handled'] !== $state['lag']) {
                    $this->enqueue('version_lag',$state['lag'],$row,$site,$settings,$wall,$comparison);
                    $state['lag_handled'] = $state['lag'];
                }
            }
        }
        $this->execute('INSERT INTO notification_checkpoints (site_id,last_history_id,config_revision,state) VALUES (?,?,?,?)
            ON CONFLICT(site_id) DO UPDATE SET last_history_id = excluded.last_history_id,
            config_revision = excluded.config_revision, state = excluded.state',
            [$row['site_id'],$historyId,$row['config_revision'],json_encode($state,JSON_THROW_ON_ERROR)]);
    }

    private function confirm(array &$state, string $key, string $sample, int $wall): bool
    {
        if ($state[$key.'_since'] === null) { $state[$key.'_since']=$sample; $state[$key.'_wall']=$wall; }
        $state[$key.'_count'] = min(2,$state[$key.'_count']+1);
        return $state[$key.'_count'] >= 2 && strtotime($sample)-strtotime($state[$key.'_since']) >= 60
            && $wall-$state[$key.'_wall'] >= 60;
    }

    public static function comparison(array $row, #[\SensitiveParameter] array $site): string|false|null
    {
        if ($row['version_status'] !== 'ok') { return null; }
        if ($site['comparison_mode'] === 'release') {
            if ($row['release_error_code'] !== null) { return null; }
            $deployed=$row['deployed_version']; $latest=$row['latest_release'];
            foreach ([$deployed,$latest] as $version) {
                if (!is_string($version) || !preg_match('/^v?[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/D',$version)) { return null; }
            }
            return version_compare(ltrim($deployed,'v'),ltrim($latest,'v'),'<') ? 'release_lower' : false;
        }
        if ($site['comparison_mode'] === 'branch') {
            if ($row['commit_error_code'] !== null || $row['deployed_commit'] === null || $row['latest_commit'] === null) { return null; }
            return str_starts_with($row['latest_commit'],$row['deployed_commit']) ? false : 'branch_difference';
        }
        return null;
    }

    private function enqueue(string $event, int $sourceId, array $row, #[\SensitiveParameter] array $site, array $settings, int $wall, ?string $comparison): void
    {
        $key = $settings['installation_id'].':'.$event.':'.$sourceId;
        $payload=['schema'=>'tablo.notification.v1','event_id'=>$key,'event'=>$event,'subject_id'=>$row['site_id'],'observed_at'=>$row['checked_at']];
        if ($comparison !== null) { $payload['comparison']=$comparison; }
        $private=[];
        if ($settings['include_name']) { $private['display_name']=$site['name']; }
        if ($settings['include_url']) {
            $parts=parse_url($site['url']);
            $private['site_origin']=$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }
        $cipher=null;
        if ($private !== []) { $this->vault->assertAvailable(true); $cipher=$this->vault->encrypt(json_encode($private,JSON_THROW_ON_ERROR)); }
        $this->execute("INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,
            created_at,expires_at,due_at,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
            ON CONFLICT(site_id,event) DO UPDATE SET event_id=excluded.event_id,source_id=excluded.source_id,
            config_revision=excluded.config_revision,channel_revision=excluded.channel_revision,payload=excluded.payload,
            private_cipher=excluded.private_cipher,created_at=excluded.created_at,expires_at=excluded.expires_at,due_at=excluded.due_at,
            coalesced=notification_slots.coalesced+CASE WHEN notification_slots.status IN ('pending','inflight') THEN 1 ELSE 0 END,
            status='pending',attempts=0,claim=NULL,lease_until=NULL,last_code=NULL,http_status=NULL,acknowledged_at=NULL
            WHERE notification_slots.event_id <> excluded.event_id",
            [$row['site_id'],$event,$key,$sourceId,$row['config_revision'],$settings['revision'],json_encode($payload,JSON_THROW_ON_ERROR),$cipher,$wall,$wall+3600,$wall]);
    }

    private function one(string $sql, array $values=[]): array|false
    {
        $query=$this->db->prepare($sql);
        try { $query->execute($values); return $query->fetch(); } finally { $query->closeCursor(); }
    }

    private function execute(string $sql, #[\SensitiveParameter] array $values): void
    {
        $query=$this->db->prepare($sql);
        try { $query->execute($values); } finally { $query->closeCursor(); }
    }
}
