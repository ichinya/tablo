<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"attempts": int, "last_code": null|string, "status": string, ...} */
final class SqlNotificationSlotsAttemptsLastCodeStatusRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['attempts'] = SqlColumn::integer($row, 'attempts');
        $row['last_code'] = SqlColumn::nullableString($row, 'last_code');
        $row['status'] = SqlColumn::string($row, 'status');
        return $row;
    }
}
