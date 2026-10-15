<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"failures": int, "key": null|string, "window_start": int, ...} */
final class SqlLoginLimitsFailuresKeyWindowStartRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['failures'] = SqlColumn::integer($row, 'failures');
        $row['key'] = SqlColumn::nullableString($row, 'key');
        $row['window_start'] = SqlColumn::integer($row, 'window_start');
        return $row;
    }
}
