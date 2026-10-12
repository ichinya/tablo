<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"bearer_present": int, "blocked": int, "enabled": int, "endpoint_present": int, "include_name": int, "include_url": int, "recovery": int, "revision": int, "unavailable": int, "version_lag": int, ...} */
final class SqlNotificationSettingsBearerPresentBlockedEnabledRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['bearer_present'] = SqlColumn::integer($row, 'bearer_present');
        $row['blocked'] = SqlColumn::integer($row, 'blocked');
        $row['enabled'] = SqlColumn::integer($row, 'enabled');
        $row['endpoint_present'] = SqlColumn::integer($row, 'endpoint_present');
        $row['include_name'] = SqlColumn::integer($row, 'include_name');
        $row['include_url'] = SqlColumn::integer($row, 'include_url');
        $row['recovery'] = SqlColumn::integer($row, 'recovery');
        $row['revision'] = SqlColumn::integer($row, 'revision');
        $row['unavailable'] = SqlColumn::integer($row, 'unavailable');
        $row['version_lag'] = SqlColumn::integer($row, 'version_lag');
        return $row;
    }
}
