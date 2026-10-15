<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"clock_invalid": int, "config_revision": int, "end_reason": null|string, "health_error_code": null|string, "health_http_status": null|int, "id": int, "interruption_history_id": null|int, "opened_at": string, "recovered_at": null|string, "recovery_history_id": null|int, "site_id": int, "uncertain": int, ...} */
final class SqlIncidentsClockInvalidConfigRevisionEndReasonRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['clock_invalid'] = SqlColumn::integer($row, 'clock_invalid');
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['end_reason'] = SqlColumn::nullableString($row, 'end_reason');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['interruption_history_id'] = SqlColumn::nullableInteger($row, 'interruption_history_id');
        $row['opened_at'] = SqlColumn::string($row, 'opened_at');
        $row['recovered_at'] = SqlColumn::nullableString($row, 'recovered_at');
        $row['recovery_history_id'] = SqlColumn::nullableInteger($row, 'recovery_history_id');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['uncertain'] = SqlColumn::integer($row, 'uncertain');
        return $row;
    }
}
