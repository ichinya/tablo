<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Testo\Assert;

final class BarrierSignal
{
    public static function await(string $path): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($path) && microtime(true) < $deadline) {
            clearstatcache();
            usleep(20_000);
        }
        Assert::true(is_file($path));
    }
}
