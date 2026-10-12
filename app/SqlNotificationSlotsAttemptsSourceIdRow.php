<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"attempts": int, "source_id": int, ...} */
final class SqlNotificationSlotsAttemptsSourceIdRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['attempts'] = SqlColumn::integer($row, 'attempts');
        $row['source_id'] = SqlColumn::integer($row, 'source_id');
        return $row;
    }
}
