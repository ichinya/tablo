<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** A required live fixture value is returned unchanged; missing ownership is loud. */
final class FixtureValue
{
    /**
     * @template T of object
     * @param T|null $value
     * @return T
     */
    public static function object(#[\SensitiveParameter] ?object $value): object
    {
        if ($value === null) { throw new RuntimeException('Required fixture object is unavailable.'); }
        return $value;
    }

    /**
     * @template T of array
     * @param T|null $value
     * @return T
     */
    public static function row(#[\SensitiveParameter] ?array $value): array
    {
        if ($value === null) { throw new RuntimeException('Required fixture row is unavailable.'); }
        return $value;
    }

    /**
     * @template T of array
     * @param T|false|null $value
     * @return T
     */
    public static function fetched(#[\SensitiveParameter] array|false|null $value): array
    {
        if ($value === null) { throw new RuntimeException('Required fixture row is unavailable.'); }
        if ($value === false) { throw new RuntimeException('Required fixture fetched row is unavailable.'); }
        return $value;
    }

    /**
     * @template K of array-key
     * @template T
     * @param array<K, T> $values
     * @param K $key
     * @return T
     */
    public static function at(#[\SensitiveParameter] array $values, int|string $key): mixed
    {
        if (!array_key_exists($key, $values)) { throw new RuntimeException('Required fixture index is unavailable.'); }
        return $values[$key];
    }

    public static function string(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) { throw new RuntimeException('Required fixture string is unavailable.'); }
        return $value;
    }

    public static function integer(#[\SensitiveParameter] mixed $value): int
    {
        if (!is_int($value)) { throw new RuntimeException('Required fixture integer is unavailable.'); }
        return $value;
    }

    public static function boolean(#[\SensitiveParameter] mixed $value): bool
    {
        if (!is_bool($value)) { throw new RuntimeException('Required fixture boolean is unavailable.'); }
        return $value;
    }

    /** Raw PDO rows retain false and every payload field without domain decoding. */
    public static function databaseRow(#[\SensitiveParameter] mixed $value): array|false
    {
        if ($value === false) { return false; }
        if (!is_array($value)) { throw new RuntimeException('Required fixture database row is unavailable.'); }
        return $value;
    }
}
