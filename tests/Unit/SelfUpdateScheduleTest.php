<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\BuildMetadata;
use Tablo\SelfUpdateCheck;
use Tablo\SelfUpdatePresentation;
use Tablo\SelfUpdateRepository;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\SelfUpdateHttp;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateScheduleTest
{
    #[Test]
    public function invalidBranchMetadataBecomesNoDataWhileValidNamesKeepTheirExactEncodedPath(): void
    {
        foreach (['', '@', '.main', 'feature/.secret', 'topic.lock', 'topic.lock/child', 'feature/topic.lock', '/main', 'main/', 'a//b',
            'a..b', 'a@{b', 'a b', 'a~b', 'a^b', 'a:b', 'a?b', 'a*b', 'a[b', 'a\\b', 'main.', '-main', "a\0b", "a\x7fb", str_repeat('a', times: 201)] as $branch) {
            self_update_branch_metadata($branch, status: 'no_data');
        }
        foreach (['main', 'feature/topic', 'release/v1.2', 'feature/foo./bar', 'topic/@', 'feature/@topic', 'topic.LOCK', 'topic.locked',
            'фича/ветка', str_repeat('a', times: 200)] as $branch) { self_update_branch_metadata($branch, status: 'current'); }
    }

    #[Test]
    public function usesSixHourDueTimeAndBoundedRetriesWithoutErasingLastSuccess(): void
    {
        $db = A::database(); $http = new SelfUpdateHttp(); $now = 1000; $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $repository = new SelfUpdateRepository($db); $check = new SelfUpdateCheck($db, $http, static function () use (&$now): int { return $now; }, $build);
        $http->branch(str_repeat('b', times: 40)); $http->comparison('ahead', ahead: 7); $check->runDue(static fn (): bool => false);
        $success = $repository->get(); Assert::same($success['next_due'], 22_600);
        Assert::same(SelfUpdatePresentation::saved($repository, $build, $now)['message'], 'В ветке main доступны новые коммиты. Обновите Tablo.');
        $now = 22_599; $check->runDue(static fn (): bool => false); Assert::same(count($http->urls), 2);
        foreach ([300, 1800, 7200, 21_600] as $delay) {
            $now = $repository->get()['next_due']; $http->responses[] = UnitFixtures::response(503);
            $check->runDue(static fn (): bool => false); $row = $repository->get();
            Assert::same($row['next_due'], $now + $delay); Assert::same($row['successful_at'], $success['successful_at']);
            Assert::same($row['latest_sha'], $success['latest_sha']); Assert::same($row['status'], 'behind');
            Assert::true(SelfUpdatePresentation::saved($repository, $build, $now)['stale']);
        }
        $repository->configure(null, '360'); $now += 100_000; $check->runDue(static fn (): bool => false); Assert::same(count($http->urls), 6);
    }

    #[Test]
    public function missingMetadataNeverFetchesRemoteHeadAndDoesNotClaimSuccessfulVerification(): void
    {
        $db = A::database(); $http = new SelfUpdateHttp();
        $build = new BuildMetadata('ichinya/tablo', 'main', '');
        (new SelfUpdateCheck($db, $http, static fn (): int => 1000, $build))->runDue(static fn (): bool => false);
        $row = (new SelfUpdateRepository($db))->get(); Assert::same($http->urls, []);
        Assert::same($row['status'], 'no_data'); Assert::same($row['successful_at'], null); Assert::same($row['next_due'], 22_600);
        Assert::same($row['latest_sha'], null);
    }
}

function self_update_branch_metadata(string $branch, string $status): void
{
    $db = A::database(); $http = new SelfUpdateHttp(); $build = new BuildMetadata('ichinya/tablo', $branch, str_repeat('a', times: 40));
    Assert::same($build->valid(), $status === 'current');
    if ($status === 'current') { $http->branch($build->sha, $branch); }
    (new SelfUpdateCheck($db, $http, static fn (): int => 1000, $build))->runDue(static fn (): bool => false);
    $row = (new SelfUpdateRepository($db))->get(); Assert::same($row['status'], $status);
    Assert::same($http->urls, $status === 'current' ? ['https://api.github.com/repos/ichinya/tablo/branches/' . rawurlencode($branch)] : []);
    Assert::same([$row['successful_at'], $row['latest_sha']], $status === 'current' ? [1000, $build->sha] : [null, null]);
    Assert::same([$row['error_code'], $row['failures'], $row['claim_token']], [null, 0, null]);
}
