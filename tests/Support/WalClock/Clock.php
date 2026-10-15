<?php
declare(strict_types=1);

namespace Tablo\Tests\Support\WalClock;

final class Clock
{
    public static int $now = 0;
    public static int $overshoot = 0;
    public static array $sleeps = [];
    public static function reset(int $overshoot): void { self::$now = 0; self::$overshoot = $overshoot; self::$sleeps = []; }
}

function hrtime(bool $asNumber = false): int { return Clock::$now; }
function usleep(int $microseconds): void
{
    Clock::$sleeps[] = ['before' => Clock::$now, 'microseconds' => $microseconds];
    Clock::$now += $microseconds * 1000 + Clock::$overshoot;
}
