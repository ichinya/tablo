<?php
declare(strict_types=1);

namespace Tablo;

final class MonotonicTime
{
    public static function nanoseconds(): int
    {
        return self::integer(hrtime(true));
    }
    /** Validate an observation made at the caller's own clock seam. */
    public static function integer(int|float|false $value): int
    {
        if (!is_int($value)) { throw new \TypeError('Integer monotonic clock is unavailable.'); }
        return $value;
    }
}
