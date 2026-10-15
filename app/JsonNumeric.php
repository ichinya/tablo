<?php
declare(strict_types=1);

namespace Tablo;

final class JsonNumeric
{
    public static function number(#[\SensitiveParameter] string $value): int|float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new \InvalidArgumentException('Для этого условия нужно конечное число.');
        }
        // Unary numeric conversion retains integers beyond exact float precision.
        // Addition by zero also canonicalized negative float zero; retain that result.
        $number = +$value;
        return $number === 0.0 ? 0.0 : $number;
    }
}
