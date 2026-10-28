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
        Assert::same($repository->get()['error_code'], 'stopped');
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
}
