<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\BuildMetadata;
use Tablo\SelfUpdateCheck;
use Tablo\SelfUpdateRepository;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\SelfUpdateHttp;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateFailureTest
{
    #[Test]
    public function sharedRateLimitDefersRetriesAndStopPreventsLaterRequests(): void
    {
        $db = A::database(); $http = new SelfUpdateHttp(); $now = 1000;
        $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $check = new SelfUpdateCheck($db, $http, static function () use (&$now): int { return $now; }, $build);
        $http->responses[] = UnitFixtures::response(403) + ['headers' => ['x-ratelimit-resource' => 'core', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '9000']];
        $check->runDue(static fn (): bool => false);
        $repository = new SelfUpdateRepository($db); Assert::same($repository->get()['next_due'], 9000);
        $now = 8999; $check->runDue(static fn (): bool => false); Assert::same(count($http->urls), 1);
        $now = 9000; $http->branch(str_repeat('b', times: 40));
        $check->runDue(static fn (): bool => count($http->urls) > 1);
        Assert::same(count($http->urls), 2); Assert::same($repository->get()['successful_at'], null);
        Assert::same($repository->get()['error_code'], 'rate-limit');
        Assert::same([$repository->get()['failures'], $repository->get()['next_due'], $repository->get()['claim_token']], [1, 9000, null]);
    }

    #[Test]
    public function invalidComparisonOrDifferentBranchCannotInventCurrentOrUpdate(): void
    {
        foreach (['branch', 'compare'] as $fault) {
            $db = A::database(); $http = new SelfUpdateHttp(); $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
            $http->branch(str_repeat('b', times: 40), $fault === 'branch' ? 'other' : 'main');
            if ($fault === 'compare') { $http->comparison('identical'); }
            (new SelfUpdateCheck($db, $http, static fn (): int => 1000, $build))->runDue(static fn (): bool => false);
            $row = (new SelfUpdateRepository($db))->get(); Assert::same($row['status'], 'no_data');
            Assert::same($row['successful_at'], null); Assert::true($row['error_code'] !== null);
        }
    }

    #[Test]
    public function stopReleasesClaimAndRestartChecksImmediatelyWithoutSpendingRetryBudget(): void
    {
        $db = A::database(); $http = new SelfUpdateHttp(); $now = 1000; $stop = false;
        $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $clock = static function () use (&$now): int { return $now; };
        $stopping = static function () use (&$stop): bool { return $stop; };
        $check = new SelfUpdateCheck($db, $http, $clock, $build); $repository = new SelfUpdateRepository($db);
        $http->branch(str_repeat('b', times: 40)); $http->comparison('ahead', ahead: 2); $check->runDue($stopping);
        $successful = $repository->get(); $now = $successful['next_due'];
        $http->branch(str_repeat('b', times: 40)); $http->during = static function () use (&$stop): void { $stop = true; };
        $check->runDue($stopping); $cancelled = $repository->get();
        Assert::same(array_diff_key($cancelled, ['attempted_at' => true]), array_diff_key($successful, ['attempted_at' => true]));
        Assert::same([$cancelled['claim_token'], $cancelled['claim_until'], $cancelled['failures'], $cancelled['next_due']], [null, 0, 0, $now]);
        $stop = false; $http->during = null; $http->branch(str_repeat('b', times: 40)); $http->comparison('ahead', ahead: 2);
        (new SelfUpdateCheck($db, $http, $clock, $build))->runDue($stopping);
        Assert::same([$repository->get()['successful_at'], $repository->get()['failures']], [$now, 0]);
        Assert::same(count($http->urls), 5);
        $successful = $repository->get(); $now = $successful['next_due'];
        $http->responses[] = UnitFixtures::response(503); $http->during = static function () use (&$stop): void { $stop = true; };
        $check->runDue($stopping);
        Assert::same(array_diff_key($repository->get(), ['attempted_at' => true]), array_diff_key($successful, ['attempted_at' => true]), 'stop also cancels a concurrently failed transport without retry credit');
        $stop = false; $http->during = null; $http->branch(str_repeat('b', times: 40)); $http->comparison('ahead', ahead: 2);
        $check->runDue($stopping); Assert::same([$repository->get()['successful_at'], $repository->get()['failures'], count($http->urls)], [$now, 0, 8]);
    }
}
