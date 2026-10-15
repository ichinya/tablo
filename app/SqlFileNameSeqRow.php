<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"file": string, "name": string, "seq": int, ...} */
final class SqlFileNameSeqRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['file'] = SqlColumn::string($row, 'file');
        $row['name'] = SqlColumn::string($row, 'name');
        $row['seq'] = SqlColumn::integer($row, 'seq');
        return $row;
    }
}
