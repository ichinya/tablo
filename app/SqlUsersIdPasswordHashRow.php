<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"id": int, "password_hash": string, ...} */
final class SqlUsersIdPasswordHashRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['password_hash'] = SqlColumn::string($row, 'password_hash');
        return $row;
    }
}
