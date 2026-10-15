<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use Tablo\Tests\Support\NotificationFixture as F;

/**
 * @phpstan-import-type Record from \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow as Settings
 * @phpstan-import-type Record from \Tablo\SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow as Slot
 * @phpstan-import-type Record from \Tablo\SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow as Checkpoint
 * @phpstan-type Snapshot array{notification_settings: list<Settings>, notification_slots: list<Slot>, notification_checkpoints: list<Checkpoint>}
 */
final class NotificationAssertions
{
    /** @return Snapshot */
    public static function notificationRows(F $f): array
    {
        $db = FixtureValue::object($f->db);
        return [
            'notification_settings' => \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_settings ORDER BY 1'))->fetchAll(), \Tablo\SqlNotificationSettingsActivationIdBearerCipherBlockedRow::checked(...)),
            'notification_slots' => \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_slots ORDER BY 1'))->fetchAll(), \Tablo\SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow::checked(...)),
            'notification_checkpoints' => \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_checkpoints ORDER BY 1'))->fetchAll(), \Tablo\SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow::checked(...)),
        ];
    }

}
