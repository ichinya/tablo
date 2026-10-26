<?php
declare(strict_types=1);

namespace Tablo;

final class JsonEncoding
{
    public static function encode(#[\SensitiveParameter] mixed $value, int $flags = 0): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | $flags);
        if ($encoded === false) { throw new \RuntimeException('Cannot encode JSON.'); }
        return $encoded;
    }
}
