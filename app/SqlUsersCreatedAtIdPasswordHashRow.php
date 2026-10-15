<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"created_at": string, "id": int, "password_hash": string, ...} */
final class SqlUsersCreatedAtIdPasswordHashRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['created_at'] = SqlColumn::string($row, 'created_at');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['password_hash'] = SqlColumn::string($row, 'password_hash');
        return $row;
    }
}
