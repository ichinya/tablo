<?php
declare(strict_types=1);

namespace Tablo;

use RuntimeException;

/** SQLite row decoding distinguishes absent columns from present NULL values. */
final class SqlColumn
{
    public static function string(#[\SensitiveParameter] array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) { throw new RuntimeException('Invalid database row.'); }
        return $row[$key];
    }
    public static function nullableString(#[\SensitiveParameter] array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid database row.');
        }
        return $row[$key];
    }
    public static function integer(#[\SensitiveParameter] array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null)) { throw new RuntimeException('Invalid database row.'); }
        return $row[$key];
    }
    public static function nullableInteger(#[\SensitiveParameter] array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_int($row[$key]))) {
            throw new RuntimeException('Invalid database row.');
        }
        return $row[$key];
    }
}
