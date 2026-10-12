<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"created_at": string, "encrypted_token": string, "id": int, "name": string, "provider": string, "updated_at": string, ...} */
final class SqlGitTokensCreatedAtEncryptedTokenIdRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['created_at'] = SqlColumn::string($row, 'created_at');
        $row['encrypted_token'] = SqlColumn::string($row, 'encrypted_token');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['name'] = SqlColumn::string($row, 'name');
        $row['provider'] = SqlColumn::string($row, 'provider');
        $row['updated_at'] = SqlColumn::string($row, 'updated_at');
        return $row;
    }
}
