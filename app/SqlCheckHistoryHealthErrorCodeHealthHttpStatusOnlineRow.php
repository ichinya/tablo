<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"health_error_code": null|string, "health_http_status": null|int, "online": null|int, "version_status": string, ...} */
final class SqlCheckHistoryHealthErrorCodeHealthHttpStatusOnlineRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['version_status'] = SqlColumn::string($row, 'version_status');
        return $row;
    }
}
