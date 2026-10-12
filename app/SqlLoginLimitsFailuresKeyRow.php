<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"failures": int, "key": null|string, ...} */
final class SqlLoginLimitsFailuresKeyRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['failures'] = SqlColumn::integer($row, 'failures');
        $row['key'] = SqlColumn::nullableString($row, 'key');
        return $row;
    }
}
