<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"deployed_version": null|string, "health_error_code": null|string, "health_http_status": null|int, "last_error": null|string, "latest_commit": null|string, "online": null|int, "open_issues": null|int, ...} */
final class SqlSitesDeployedVersionHealthErrorCodeHealthHttpStatusRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['deployed_version'] = SqlColumn::nullableString($row, 'deployed_version');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['last_error'] = SqlColumn::nullableString($row, 'last_error');
        $row['latest_commit'] = SqlColumn::nullableString($row, 'latest_commit');
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['open_issues'] = SqlColumn::nullableInteger($row, 'open_issues');
        return $row;
    }
}
