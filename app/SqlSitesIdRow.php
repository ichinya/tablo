<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"id": int, ...} */
final class SqlSitesIdRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['id'] = SqlColumn::integer($row, 'id');
        return $row;
    }
}
