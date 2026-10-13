<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SessionExpiry;
use Testo\Assert;
use Testo\Test;

final class SessionLifetimeTest
{
    #[Test]
    public function exactIdleAndAbsoluteBoundariesAndObservedClockOrderFailClosed(): void
    {
        Assert::true(SessionExpiry::live(1000, 1000, 1000, 1119, 2));
        Assert::false(SessionExpiry::live(1000, 1000, 1000, 1120, 2));
        Assert::false(SessionExpiry::live(1000, 1000, 1000, 1121, 2));
        Assert::true(SessionExpiry::live(1000, 44_199, 44_199, 44_199, 720));
        Assert::false(SessionExpiry::live(1000, 44_199, 44_199, 44_200, 720));
        Assert::false(SessionExpiry::live(1000, 44_199, 44_199, 44_201, 720));
        Assert::false(SessionExpiry::live(1000, 1100, 1110, 1109, 30));
        Assert::true(SessionExpiry::live(1000, 1100, 1110, 1110, 30));
        Assert::true(SessionExpiry::live(0, 0, 0, 0, 1));
        foreach ([null, '1000', 1000.0, [], false, -1, PHP_INT_MAX] as $invalid) {
            Assert::false(SessionExpiry::live($invalid, 1000, 1000, 1001, 30));
            Assert::false(SessionExpiry::live(1000, $invalid, 1000, 1001, 30));
            Assert::false(SessionExpiry::live(1000, 1000, $invalid, 1001, 30));
        }
        Assert::false(SessionExpiry::live(1000, 999, 1000, 1001, 30));
        Assert::false(SessionExpiry::live(1000, 1001, 1000, 1001, 30));
        foreach ([0, 721, -1, PHP_INT_MAX] as $minutes) { Assert::false(SessionExpiry::live(1000, 1000, 1000, 1000, $minutes)); }
    }
}
