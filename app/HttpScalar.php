<?php
declare(strict_types=1);

namespace Tablo;

final class HttpScalar
{
    public static function integer(mixed $value): int
    {
        if (!is_int($value)) { throw new HttpFailure('invalid-response'); }
        return $value;
    }
    public static function floating(mixed $value): float
    {
        if (!is_float($value)) { throw new HttpFailure('invalid-response'); }
        return $value;
    }
}
