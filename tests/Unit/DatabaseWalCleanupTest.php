<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;





use Testo\Assert;
use Testo\Test;

final class DatabaseWalCleanupTest extends WalNegotiationFixtureCase
{


    #[Test]
    public function walCleanupAndRestoreFailuresNeverRetryOrMaskPrimaryFailure(): void
    {
        foreach ([true, false] as $throwCleanup) {
            $db = new DatabaseWalPdo();
            $primary = DatabaseWalPdo::error(5);
            $db->plan['failure'] = $primary;
            $db->plan['cleanupFailure'] = $throwCleanup ? new \RuntimeException('Synthetic cleanup failure') : false;
            $db->plan['restoreFailure'] = new \RuntimeException('Synthetic restore failure');
            Assert::same(self::negotiate($db), $primary);
            Assert::same($db->setters, 1);
            Assert::same($db->timeouts, [0, 5000]);
            Assert::true($db->allReleased());
        }
        $db = new DatabaseWalPdo();
        $db->plan['restoreFailure'] = new \RuntimeException('Synthetic restore failure');
        Assert::same(self::negotiate($db), $db->plan['restoreFailure']);
        $db = new DatabaseWalPdo();
        $db->plan['cleanupFailure'] = new \RuntimeException('Synthetic cleanup failure');
        Assert::same(self::negotiate($db), $db->plan['cleanupFailure']);
        Assert::same($db->setters, 0);
        Assert::same($db->timeout, 5000);
        $db = new DatabaseWalPdo();
        $db->plan['initialFailure'] = DatabaseWalPdo::error(5);
        Assert::same(self::negotiate($db), $db->plan['initialFailure']);
        Assert::same($db->setters, 0);
        Assert::same($db->timeouts, [0, 5000]);
    }
}
