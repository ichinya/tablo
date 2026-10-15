<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;





use Testo\Assert;
use Testo\Test;

final class DatabaseWalNegotiationTest extends WalNegotiationFixtureCase
{


    #[Test]
    public function walRetriesOnlyGenuineBusyCodesAndReleasesEachStatement(): void
    {
        foreach ([5, 261, 773] as $code) {
            foreach (['prepare', 'execute', 'fetch'] as $stage) {
                $db = new DatabaseWalPdo();
                $db->plan['failure'] = DatabaseWalPdo::error($code);
                $db->plan['failureStage'] = $stage;
                Assert::same(self::negotiate($db), null);
                Assert::same($db->timeout, 5000);
                Assert::same($db->setters, 2);
                Assert::true($db->allReleased());
                Assert::same($db->timeouts, [0, 5000]);
            }
        }
        foreach ([null, '5', 6, 8, 10, 17, 517, 1029] as $code) {
            $db = new DatabaseWalPdo();
            $error = DatabaseWalPdo::error($code);
            $db->plan['failure'] = $error;
            Assert::same(self::negotiate($db), $error);
            Assert::same($db->setters, 1);
            Assert::same($db->timeout, 5000);
            Assert::true($db->allReleased());
        }
    }



    #[Test]
    public function walReadsActualModeAndImmediatelyRefusesUnsupportedMode(): void
    {
        $db = new DatabaseWalPdo();
        $db->plan['mode'] = 'wal';
        Assert::same(self::negotiate($db), null);
        Assert::same($db->setters, 0);
        Assert::same($db->sql, ['PRAGMA main.journal_mode']);
        $db = new DatabaseWalPdo();
        $db->plan['returnedMode'] = 'delete';
        Assert::same(self::negotiate($db)?->getMessage(), 'SQLite WAL is required for file databases; use writable local storage.');
        Assert::same($db->setters, 1);
        Assert::same($db->timeout, 5000);
        Assert::true($db->allReleased());
    }
}
