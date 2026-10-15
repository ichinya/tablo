<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"checked_at": null|string, "config_revision": int, "health_error_code": null|string, "health_http_status": null|int, "id": int, "online": null|int, ...} */
final class SqlSitesCheckedAtConfigRevisionHealthErrorCodeRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['checked_at'] = SqlColumn::nullableString($row, 'checked_at');
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        return $row;
    }
}
