<?php
declare(strict_types=1);

namespace Tablo;

final class HttpDeadline
{
    public static function remaining(?int $deadline): int
    {
        if ($deadline === null) { return 6000; }
        $remaining = (int) floor(($deadline - hrtime(true)) / 1_000_000);
        if ($remaining < 1) { throw new HttpFailure('timeout'); }
        return min(6000, $remaining);
    }
}
