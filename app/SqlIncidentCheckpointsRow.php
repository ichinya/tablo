<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"config_revision": int, "last_history_id": int, "site_id": int, "watermark": string, ...} */
final class SqlIncidentCheckpointsRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['last_history_id'] = SqlColumn::integer($row, 'last_history_id');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['watermark'] = SqlColumn::string($row, 'watermark');
        return $row;
    }
}
