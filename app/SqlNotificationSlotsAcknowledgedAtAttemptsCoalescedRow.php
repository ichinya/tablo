<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"acknowledged_at": null|int, "attempts": int, "coalesced": int, "due_at": int, "event": string, "expires_at": int, "http_status": null|int, "last_code": null|string, "site_id": int, "status": string, ...} */
final class SqlNotificationSlotsAcknowledgedAtAttemptsCoalescedRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['acknowledged_at'] = SqlColumn::nullableInteger($row, 'acknowledged_at');
        $row['attempts'] = SqlColumn::integer($row, 'attempts');
        $row['coalesced'] = SqlColumn::integer($row, 'coalesced');
        $row['due_at'] = SqlColumn::integer($row, 'due_at');
        $row['event'] = SqlColumn::string($row, 'event');
        $row['expires_at'] = SqlColumn::integer($row, 'expires_at');
        $row['http_status'] = SqlColumn::nullableInteger($row, 'http_status');
        $row['last_code'] = SqlColumn::nullableString($row, 'last_code');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['status'] = SqlColumn::string($row, 'status');
        return $row;
    }
}
