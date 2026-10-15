<?php
declare(strict_types=1);

namespace Tablo;

final class SchemaForeignShape
{
    public static function foreignKeys(\PDO $db, string $table): array
    {
        $rows = SchemaShape::rows($db, 'PRAGMA main.foreign_key_list("' . $table . '")');
        foreach ($rows as &$row) { unset($row['id']); }
        unset($row);
        sort($rows);
        return $rows;
    }

    public static function validate(array $actual, array $wanted, int $version, array $missing): void
    {
        if ($version === 0 && in_array('git_token_id', $missing, strict: true)) { $wanted = []; }
        if ($actual !== $wanted) { SchemaShape::refuse(); }
    }

}
