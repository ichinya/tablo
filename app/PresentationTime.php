<?php
declare(strict_types=1);

namespace Tablo;

final class PresentationTime
{
    public static function seconds(?string $value): int
    {
        if ($value === null) { throw new \TypeError('Required presentation time is unavailable.'); }
        $seconds = strtotime($value);
        if ($seconds === false) { throw new \TypeError('Required presentation time is invalid.'); }
        return $seconds;
    }
}
