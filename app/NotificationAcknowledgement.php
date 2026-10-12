<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Claim from NotificationOutbox */
final class NotificationAcknowledgement
{
    public function __construct(private readonly \PDO $db) {}
    /** @param Claim $claimed */
    public function acknowledge(#[\SensitiveParameter] array $claimed, array $result, int $now): bool
    {
        $result = NotificationResult::checked($result);
        $slot=$claimed['slot'];
        $success=$result['code']==='sent';
        $status = NotificationRetryStatus::status($claimed,$result,$now);
        $query=\Tablo\SqlStatement::checked($this->db->prepare("UPDATE notification_slots SET status=?,due_at=?,claim=NULL,lease_until=NULL,
            last_code=?,http_status=?,acknowledged_at=? WHERE site_id=? AND event=? AND event_id=? AND claim=? AND attempts=?
            AND status='inflight' AND channel_revision=? AND lease_until>? AND expires_at>? AND EXISTS(SELECT 1 FROM notification_settings
                WHERE id=1 AND revision=? AND enabled=1) AND EXISTS(SELECT 1 FROM sites WHERE id=? AND enabled=1 AND config_revision=?)"));
        try {
            $query->execute([$status,$now+($claimed['attempt']===1?60:300),$result['code'],$result['http_status']??null,$success?$now:null,
                $slot['site_id'],$slot['event'],$slot['event_id'],$claimed['claim'],$claimed['attempt'],$slot['channel_revision'],
                $now,$now,
                $slot['channel_revision'],$slot['site_id'],$slot['config_revision']]);
            return $query->rowCount()===1;
        } finally { $query->closeCursor(); }
    }
}
