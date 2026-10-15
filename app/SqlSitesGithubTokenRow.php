<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"github_token": null|string, ...} */
final class SqlSitesGithubTokenRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['github_token'] = SqlColumn::nullableString($row, 'github_token');
        return $row;
    }
}
