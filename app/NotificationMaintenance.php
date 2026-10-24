<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Record from SqlNotificationSettingsActivationIdBearerCipherBlockedRow as Settings */
final class NotificationMaintenance
{
    public function __construct(private readonly \PDO $db) {}
    /** @param Settings $settings */
    public function maintain(#[\SensitiveParameter] array $settings,int $now): void
    {
        $store = new NotificationProjectionStore($this->db);
        $cast = ServerDialect::choose($this->db, 'INTEGER', 'SIGNED');
        $query=\Tablo\SqlStatement::checked($this->db->prepare("SELECT n.*,s.enabled AS site_enabled,s.config_revision AS current_revision
            FROM notification_slots n LEFT JOIN sites s ON s.id=n.site_id WHERE
            (n.status='pending' AND n.due_at<=? AND (n.expires_at<=? OR n.attempts>=3 OR n.channel_revision<>?
                OR s.id IS NULL OR s.enabled<>1 OR s.config_revision<>n.config_revision
                OR (n.event='unavailable' AND CAST(? AS {$cast})=0) OR (n.event='recovery' AND CAST(? AS {$cast})=0) OR (n.event='version_lag' AND CAST(? AS {$cast})=0)))
            OR (n.status='inflight' AND n.lease_until<=?) ORDER BY n.due_at,n.site_id,n.event LIMIT 32"));
        try {
            $query->execute([$now,$now,$settings['revision'],$settings['unavailable'],$settings['recovery'],$settings['version_lag'],$now]);
            $rows=SqlRows::all($query->fetchAll(), NotificationMaintenanceRow::checked(...));
        } finally { $query->closeCursor(); }
        foreach($rows as $slot){
            $status = NotificationMaintenanceStatus::classify($slot,$settings,$now);
            if($status!==null){
                $store->execute('UPDATE notification_slots SET status=?,claim=NULL,lease_until=NULL WHERE site_id=? AND event=?',
                    [$status,$slot['site_id'],$slot['event']]);
                continue;
            }
            // A lost owner consumes its attempt; no free retry after lease expiry.
                $store->execute("UPDATE notification_slots SET status='pending',claim=NULL,lease_until=NULL,last_code='ambiguous',due_at=?
                    WHERE site_id=? AND event=?",[$now+($slot['attempts']===1?60:300),$slot['site_id'],$slot['event']]);
        }
    }
}
