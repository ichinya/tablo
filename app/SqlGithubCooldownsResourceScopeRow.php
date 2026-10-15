<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"resource": string, "scope": string, ...} */
final class SqlGithubCooldownsResourceScopeRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['resource'] = SqlColumn::string($row, 'resource');
        $row['scope'] = SqlColumn::string($row, 'scope');
        return $row;
    }
}
