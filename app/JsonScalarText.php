<?php
declare(strict_types=1);

namespace Tablo;

final class JsonScalarText
{

    public static function text(#[\SensitiveParameter] string|int|float|bool $value): string
    {
        if (is_bool($value)) { return $value ? 'true' : 'false'; }
        if (is_float($value)) { return json_encode($value, JSON_THROW_ON_ERROR); }
        return (string) $value;
    }

}
