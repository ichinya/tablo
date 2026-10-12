<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"deployed_version": null|string, "last_error": null|string, "online": null|int, ...} */
final class SqlSitesDeployedVersionLastErrorOnlineRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['deployed_version'] = SqlColumn::nullableString($row, 'deployed_version');
        $row['last_error'] = SqlColumn::nullableString($row, 'last_error');
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        return $row;
    }
}
