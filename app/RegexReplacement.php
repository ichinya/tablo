<?php
declare(strict_types=1);

namespace Tablo;

final class RegexReplacement
{
    public static function text(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) { throw new \TypeError('Required pattern replacement failed.'); }
        return $value;
    }
}
