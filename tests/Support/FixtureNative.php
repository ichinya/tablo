<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDOException;
use RuntimeException;

final class FixtureNative
{
    public static function pdoException(?\Throwable $value): PDOException
    {
        if (!$value instanceof PDOException) { throw new RuntimeException('Required fixture PDO exception is unavailable'); }
        return $value;
    }
    /** @return \Closure(): void */
    public static function callback(mixed $value): \Closure
    {
        if (!$value instanceof \Closure) { throw new RuntimeException('Required fixture callback is unavailable'); }
        return $value;
    }
    public static function object(mixed $value): object
    {
        if (!is_object($value)) { throw new RuntimeException('Required fixture native object is unavailable'); }
        return $value;
    }
    /** @return resource */
    public static function resource(mixed $value): mixed
    {
        if (!is_resource($value)) { throw new RuntimeException('Required fixture resource is unavailable'); }
        return $value;
    }

    public static function sqliteCode(PDOException $error): int|string|null
    {
        if (($error->errorInfo[1] ?? null) === null) { return null; }
        if (!is_int($error->errorInfo[1]) && !is_string($error->errorInfo[1])) { throw new RuntimeException('Invalid fixture SQLite diagnostic code'); }
        return $error->errorInfo[1];
    }
}
