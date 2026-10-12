<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"config_revision": int, "current_revision": null|int, "enabled": int, "event": string, "name": string, "site_id": int, "url": string, ...} */
final class SqlJoinedRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['current_revision'] = SqlColumn::nullableInteger($row, 'current_revision');
        $row['enabled'] = SqlColumn::integer($row, 'enabled');
        $row['event'] = SqlColumn::string($row, 'event');
        $row['name'] = SqlColumn::string($row, 'name');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['url'] = SqlColumn::string($row, 'url');
        return $row;
    }
}
