<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"eligible_at": int, "resource": string, "scope": string, ...} */
final class SqlGithubCooldownsEligibleAtResourceScopeRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['eligible_at'] = SqlColumn::integer($row, 'eligible_at');
        $row['resource'] = SqlColumn::string($row, 'resource');
        $row['scope'] = SqlColumn::string($row, 'scope');
        return $row;
    }
}
