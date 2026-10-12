<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"encrypted_token": string, ...} */
final class SqlGitTokensEncryptedTokenRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['encrypted_token'] = SqlColumn::string($row, 'encrypted_token');
        return $row;
    }
}
