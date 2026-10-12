<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"config_revision": int, "latest_commit": int, "latest_release": int, "open_issues": int, "open_prs": int, "site_id": int, ...} */
final class SqlWorkerProgressRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['latest_commit'] = SqlColumn::integer($row, 'latest_commit');
        $row['latest_release'] = SqlColumn::integer($row, 'latest_release');
        $row['open_issues'] = SqlColumn::integer($row, 'open_issues');
        $row['open_prs'] = SqlColumn::integer($row, 'open_prs');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        return $row;
    }
}
