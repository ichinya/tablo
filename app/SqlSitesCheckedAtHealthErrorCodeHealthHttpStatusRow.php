<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"checked_at": null|string, "health_error_code": null|string, "health_http_status": null|int, ...} */
final class SqlSitesCheckedAtHealthErrorCodeHealthHttpStatusRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['checked_at'] = SqlColumn::nullableString($row, 'checked_at');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        return $row;
    }
}
