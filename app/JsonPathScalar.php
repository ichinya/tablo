<?php
declare(strict_types=1);

namespace Tablo;

final class JsonPathScalar
{
    public static function checked(#[\SensitiveParameter] mixed $value): string|int|float|bool
    {
        if ($value === null) {
            throw new \RuntimeException('JSON-поле содержит null.');
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new \RuntimeException('JSON path должен выбирать одно значение, а не объект или массив.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \RuntimeException('JSON-поле содержит некорректное число.');
        }
        return $value;
    }
}
