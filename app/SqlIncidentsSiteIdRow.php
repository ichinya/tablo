<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"site_id": int, ...} */
final class SqlIncidentsSiteIdRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        return $row;
    }
}
