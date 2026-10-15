<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;





use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabaseWalClockTest extends WalNegotiationFixtureCase
{


    #[Test]
    public function walDeadlineClipsBackoffAndChecksAgainAfterSchedulerWake(): void
    {
        $owner = 'Tablo\\Tests\\Support\\WalClock\\WalNegotiation';
        if (!class_exists($owner, false)) {
            $source = file_get_contents(dirname(__DIR__, levels: 2) . '/app/WalNegotiation.php');
            if (substr_count($source, needle: 'namespace Tablo;') !== 1) { throw new \RuntimeException('Clock copy namespace changed'); }
            $directory = new TemporaryDirectory('tablo-wal-clock-');
            try {
                $copy = str_replace('namespace Tablo;', replace: "namespace Tablo\\Tests\\Support\\WalClock;\nuse Tablo\\WalAttempt;\nuse Tablo\\WalRetryAdmission;", subject: $source);
                file_put_contents($directory->path . '/owner.php', $copy);
                require $directory->path . '/owner.php';
            } finally { $directory->close(); }
        }
        foreach ([0, 30_000_000] as $overshoot) {
            \Tablo\Tests\Support\WalClock\Clock::reset($overshoot);
            $db = new DatabaseWalPdo();
            $db->plan['failure'] = DatabaseWalPdo::error(5);
            $db->plan['repeatFailure'] = true;
            Assert::same(self::negotiate($db, $owner), $db->plan['failure']);
            Assert::same($db->timeout, 5000);
            $sleeps = \Tablo\Tests\Support\WalClock\Clock::$sleeps;
            Assert::same(array_slice(array_column($sleeps, 'microseconds'), offset: 0, length: 3), [5000, 10_000, 20_000]);
            foreach ($sleeps as $sleep) {
                Assert::true($sleep['microseconds'] <= 25_000);
                Assert::true($sleep['before'] + $sleep['microseconds'] * 1000 <= 5_000_000_000);
            }
            foreach ($db->attemptTimes as $time) { Assert::true($time < 5_000_000_000); }
            Assert::true($db->allReleased());
        }
    }
}
