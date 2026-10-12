<?php
declare(strict_types=1);

namespace Tablo;

use Closure;
use RuntimeException;

/** SQL owners supply a real decoder; acquisition, fetch state and cursor ownership remain theirs. */
final class SqlRows
{
    /**
     * @template T of array
     * @param Closure(array): T $decode
     * @return T
     */
    public static function required(#[\SensitiveParameter] mixed $row, #[\SensitiveParameter] Closure $decode): array
    {
        // These producers already require an array return; keep their TypeError admission.
        if (!is_array($row)) { throw new \TypeError('Required database row is unavailable.'); }
        return $decode($row);
    }

    /**
     * @template T of array
     * @param Closure(array): T $decode
     * @return T|false
     */
    public static function one(#[\SensitiveParameter] mixed $row, #[\SensitiveParameter] Closure $decode): array|false
    {
        if ($row === false) { return false; }
        if (!is_array($row)) { throw new RuntimeException('Invalid database row.'); }
        return $decode($row);
    }

    /**
     * @template T of array
     * @param Closure(array): T $decode
     * @return list<T>
     */
    public static function all(#[\SensitiveParameter] array $rows, #[\SensitiveParameter] Closure $decode): array
    {
        $decoded = [];
        foreach (array_keys($rows) as $key) {
            if (!is_array($rows[$key] ?? null)) { throw new RuntimeException('Invalid database row.'); }
            $decoded[] = $decode($rows[$key]);
        }
        return $decoded;
    }
}
