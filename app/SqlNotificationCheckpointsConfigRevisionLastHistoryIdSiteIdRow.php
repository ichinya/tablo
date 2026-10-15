<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"config_revision": int, "last_history_id": int, "site_id": int, "state": string, ...} */
final class SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['last_history_id'] = SqlColumn::integer($row, 'last_history_id');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['state'] = SqlColumn::string($row, 'state');
        return $row;
    }
}
