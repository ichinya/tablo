<?php
declare(strict_types=1);

namespace Tablo;

use RuntimeException;

final class JsonRecord
{
    public static function decode(#[\SensitiveParameter] string $json, int $depth = 16): array
    {
        return self::checked(json_decode($json, associative: true, depth: $depth, flags: JSON_THROW_ON_ERROR));
    }

    public static function checked(#[\SensitiveParameter] mixed $value): array
    {
        if (!is_array($value)) { throw new RuntimeException('Invalid JSON record.'); }
        return $value;
    }
}
