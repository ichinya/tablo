<?php
declare(strict_types=1);

namespace Tablo;

final class SqlValue
{
    public static function integerOrFalse(#[\SensitiveParameter] mixed $value): int|false
    {
        if ($value === false || is_int($value)) { return $value; }
        throw new \RuntimeException('Invalid database value.');
    }
}
