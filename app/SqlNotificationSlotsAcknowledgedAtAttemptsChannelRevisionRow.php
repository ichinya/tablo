<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"acknowledged_at": null|int, "attempts": int, "channel_revision": int, "claim": null|string, "coalesced": int, "config_revision": int, "created_at": int, "due_at": int, "event": string, "event_id": string, "expires_at": int, "http_status": null|int, "last_code": null|string, "lease_until": null|int, "payload": string, "private_cipher": null|string, "site_id": int, "source_id": int, "status": string, ...} */
final class SqlNotificationSlotsAcknowledgedAtAttemptsChannelRevisionRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['acknowledged_at'] = SqlColumn::nullableInteger($row, 'acknowledged_at');
        $row['attempts'] = SqlColumn::integer($row, 'attempts');
        $row['channel_revision'] = SqlColumn::integer($row, 'channel_revision');
        $row['claim'] = SqlColumn::nullableString($row, 'claim');
        $row['coalesced'] = SqlColumn::integer($row, 'coalesced');
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['created_at'] = SqlColumn::integer($row, 'created_at');
        $row['due_at'] = SqlColumn::integer($row, 'due_at');
        $row['event'] = SqlColumn::string($row, 'event');
        $row['event_id'] = SqlColumn::string($row, 'event_id');
        $row['expires_at'] = SqlColumn::integer($row, 'expires_at');
        $row['http_status'] = SqlColumn::nullableInteger($row, 'http_status');
        $row['last_code'] = SqlColumn::nullableString($row, 'last_code');
        $row['lease_until'] = SqlColumn::nullableInteger($row, 'lease_until');
        $row['payload'] = SqlColumn::string($row, 'payload');
        $row['private_cipher'] = SqlColumn::nullableString($row, 'private_cipher');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['source_id'] = SqlColumn::integer($row, 'source_id');
        $row['status'] = SqlColumn::string($row, 'status');
        return $row;
    }
}
