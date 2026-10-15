<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class FixtureBarrier
{
    public static function awaitFile(string $directory, string $file): void
    {
        $deadline = \Tablo\MonotonicTime::integer(hrtime(true)) + 10_000_000_000;
        do {
            clearstatcache();
            if (FixtureFiles::exists($directory . '/' . $file)) { return; }
            usleep(20_000);
        } while (\Tablo\MonotonicTime::integer(hrtime(true)) < $deadline);
        throw new \RuntimeException('Fixture barrier timed out: ' . $file);
    }
}
