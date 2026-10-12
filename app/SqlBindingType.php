<?php
declare(strict_types=1);

namespace Tablo;

final class SqlBindingType
{
    public static function forValue(#[\SensitiveParameter] mixed $value): int
    {
        if ($value === null) { return \PDO::PARAM_NULL; }
        return is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
    }
}
