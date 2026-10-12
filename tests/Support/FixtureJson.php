<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** Admission for observed child fields; never substitutes expected values. */
final class FixtureJson
{
    public static function boolean(array $row, string $key): bool
    {
        if (!is_bool($row[$key] ?? null)) { throw new RuntimeException('Invalid fixture boolean field.'); }
        return $row[$key];
    }

    public static function number(array $row, string $key): int|float
    {
        if (!is_int($row[$key] ?? null) && !is_float($row[$key] ?? null)) { throw new RuntimeException('Invalid fixture numeric field.'); }
        return $row[$key];
    }

    public static function nullableBoolean(array $row, string $key): ?bool
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_bool($row[$key]))) {
            throw new RuntimeException('Invalid fixture nullable boolean field.');
        }
        return $row[$key];
    }

    public static function sqliteCode(array $row, string $key): int|string|null
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_int($row[$key]) && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid fixture SQLite code field.');
        }
        return $row[$key];
    }
}
