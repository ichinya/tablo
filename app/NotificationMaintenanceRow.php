<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type Row array{acknowledged_at: ?int, attempts: int, channel_revision: int,
 * claim: ?string, coalesced: int, config_revision: int, created_at: int, due_at: int,
 * event: string, event_id: string, expires_at: int, http_status: ?int, last_code: ?string,
 * lease_until: ?int, payload: string, private_cipher: ?string, site_id: int, source_id: int,
 * status: string, site_enabled: ?int, current_revision: ?int, ...}
 */
final class NotificationMaintenanceRow
{
    /** @return Row */
    public static function checked(array $row): array
    {
        $slot = SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow::checked($row);
        $slot['site_enabled'] = SqlColumn::nullableInteger($row, 'site_enabled');
        $slot['current_revision'] = SqlColumn::nullableInteger($row, 'current_revision');
        return $slot;
    }
}
