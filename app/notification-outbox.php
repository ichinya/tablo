<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/**
 * @phpstan-import-type Record from SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow as Slot
 * @phpstan-import-type Record from SqlNotificationSettingsActivationIdBearerCipherBlockedRow as Settings
 * @phpstan-import-type Result from NotificationResult
 * @phpstan-type Claim array{slot: Slot, claim: string, attempt: int, settings: Settings}
 */
final class NotificationOutbox
{
    public function __construct(private readonly PDO $db) {}

    public function status(): array
    {
        return (new NotificationStatus($this->db))->readStatus(null);
    }

    public function statusForSite(int $siteId): array
    {
        if ($siteId<1) { throw new \InvalidArgumentException('Invalid notification site.'); }
        return (new NotificationStatus($this->db))->readStatus($siteId);
    }





    // At most32 maintenance updates and one eligible claim/pass. Caller owns channel mutex.
    /** @return Claim|false */
    public function claim(int $now): array|false
    {
        $store = new NotificationProjectionStore($this->db);
        $transaction = WriteTransaction::begin($this->db);
        try {
            $settings=\Tablo\SqlRows::required($store->one('SELECT * FROM notification_settings WHERE id = 1'), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...));
            if ($settings['blocked'] || !$settings['enabled'] || $settings['endpoint_cipher'] === null
                || !($settings['unavailable'] || $settings['recovery'] || $settings['version_lag'])) {
                $transaction->commit(); return false;
            }
            (new NotificationMaintenance($this->db))->maintain($settings,$now);
            // Invalid/expired rows cannot consume the fresh event's entire one-hour TTL.
            $cast = ServerDialect::choose($this->db, 'INTEGER', 'SIGNED');
            $index = ServerDialect::choose($this->db, 'INDEXED BY notification_eligible', 'FORCE INDEX (notification_eligible)');
            $slot=\Tablo\SqlRows::one($store->one("SELECT n.* FROM notification_slots n {$index}
                JOIN sites s ON s.id=n.site_id WHERE n.status='pending' AND n.channel_revision=? AND n.expires_at>?
                AND n.attempts<3 AND n.due_at<=? AND s.enabled=1 AND s.config_revision=n.config_revision
                AND ((n.event='unavailable' AND CAST(? AS {$cast})=1) OR (n.event='recovery' AND CAST(? AS {$cast})=1) OR (n.event='version_lag' AND CAST(? AS {$cast})=1))
                ORDER BY n.due_at,n.site_id,n.event LIMIT 1",[$settings['revision'],$now,$now,
                    $settings['unavailable'],$settings['recovery'],$settings['version_lag']]), \Tablo\SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow::checked(...));
            if ($slot === false) { $transaction->commit(); return false; }
            $claim=bin2hex(random_bytes(16));
            $store->execute("UPDATE notification_slots SET status='inflight',claim=?,lease_until=?,attempts=attempts+1
                WHERE site_id=? AND event=?",[$claim,$now+30,$slot['site_id'],$slot['event']]);
            $transaction->commit();
            return ['slot'=>$slot,'claim'=>$claim,'attempt'=>$slot['attempts']+1,'settings'=>$settings];
        } catch (\Throwable $error) {
            $transaction->rollbackPreserving();
            throw $error;
        }
    }







    public function statusTruncated(): bool { return (new NotificationStatus($this->db))->statusTruncated(); }
    /** @param Claim $claimed */
    public function acknowledge(#[\SensitiveParameter] array $claimed,array $result,int $now): bool { return (new NotificationAcknowledgement($this->db))->acknowledge($claimed,$result,$now); }
}
