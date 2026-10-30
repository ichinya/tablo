<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\BuildMetadata;
use Tablo\SelfUpdateCheck;
use Tablo\SelfUpdateRepository;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\SelfUpdateHttp;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateComparisonTest
{
    #[Test]
    public function reusesInstalledComparisonForObservedTransitionWithoutLosingStopOrForcePushRules(): void
    {
        foreach (['ahead', 'behind', 'diverged', 'missing', 'stopped'] as $remote) { self_update_reused_comparison($remote); }
        self_update_fixture_comparisons();
    }

    #[Test]
    public function exhaustedQuotaOnSuccessfulComparisonDoesNotTurnReusedEvidenceIntoFailure(): void
    {
        $db = A::database(); $http = new SelfUpdateHttp(); $now = 1000;
        $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $check = new SelfUpdateCheck($db, $http, static function () use (&$now): int { return $now; }, $build);
        $repository = new SelfUpdateRepository($db);
        $http->branch($build->sha); $check->runDue(static fn (): bool => false);
        $now = 22_600; $latest = str_repeat('b', times: 40); $http->branch($latest); $http->comparison('ahead', ahead: 2);
        $http->responses[1]['headers'] = ['x-ratelimit-resource' => 'core', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '90000'];
        $check->runDue(static fn (): bool => false); $row = $repository->get();
        Assert::same($http->urls, ['https://api.github.com/repos/ichinya/tablo/branches/main', 'https://api.github.com/repos/ichinya/tablo/branches/main',
            'https://api.github.com/repos/ichinya/tablo/compare/' . $build->sha . '...' . $latest]);
        Assert::same([$row['status'], $row['new_commits'], $row['error_code'], $row['failures'], $row['successful_at']], ['behind', 2, null, 0, $now]);
        Assert::same((new \Tablo\GitHubCooldownRepository($db))->eligibleAt('core'), 90_000);
        $now = $row['next_due']; $check->runDue(static fn (): bool => false); $deferred = $repository->get();
        Assert::same(count($http->urls), 3, 'persisted quota blocks a new due check before network');
        Assert::same([$deferred['status'], $deferred['successful_at'], $deferred['error_code'], $deferred['next_due']], ['behind', 22_600, 'rate-limit', 90_000]);
    }

    #[Test]
    public function distinguishesDirectionDivergenceMissingInstalledAndExactBranch(): void
    {
        foreach (['ahead' => ['behind', 3, 0], 'behind' => ['ahead', 0, 2], 'diverged' => ['diverged', 2, 1], 'missing' => ['unknown', 0, 0]] as $remote => [$expected, $ahead, $behind]) {
            $db = A::database(); $http = new SelfUpdateHttp(); $build = new BuildMetadata('ichinya/tablo', 'feature/a b', str_repeat('a', times: 40));
            Assert::false($build->valid());
            $build = new BuildMetadata('ichinya/tablo', 'feature/a', str_repeat('a', times: 40));
            $latest = str_repeat('b', times: 40); $http->branch($latest, 'feature/a');
            if ($remote === 'missing') { $http->responses[] = \Tablo\Tests\Support\UnitFixtures::response(404); }
            if ($remote !== 'missing') { $http->comparison($remote, $ahead, $behind); }
            (new SelfUpdateCheck($db, $http, static fn (): int => 1000, $build))->runDue(static fn (): bool => false);
            $row = (new SelfUpdateRepository($db))->get(); Assert::same($row['status'], $expected);
            Assert::same($row['new_commits'], $expected === 'behind' ? 3 : null);
            Assert::same($http->urls, ['https://api.github.com/repos/ichinya/tablo/branches/feature%2Fa', 'https://api.github.com/repos/ichinya/tablo/compare/' . $build->sha . '...' . $latest]);
        }
    }

    #[Test]
    public function retainsObservedForcePushAcrossIdenticalRepeatedResults(): void
    {
        $db = A::database(); $http = new SelfUpdateHttp(); $now = 1000; $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $check = new SelfUpdateCheck($db, $http, static function () use (&$now): int { return $now; }, $build);
        $first = str_repeat('b', times: 40); $next = str_repeat('c', times: 40);
        $http->branch($first); $http->comparison('ahead', ahead: 3); $check->runDue(static fn (): bool => false);
        $now += 21_600; $http->branch($next); $http->comparison('ahead', ahead: 4); $http->comparison('diverged', ahead: 2, behind: 1);
        $check->runDue(static fn (): bool => false);
        Assert::same((new SelfUpdateRepository($db))->get()['status'], 'force_pushed');
        $now += 21_600; $http->branch($next); $http->comparison('ahead', ahead: 4); $check->runDue(static fn (): bool => false);
        Assert::same((new SelfUpdateRepository($db))->get()['status'], 'force_pushed'); Assert::same(count($http->urls), 7);
        $updated = new BuildMetadata('ichinya/tablo', 'main', $next); $http->branch($next);
        (new SelfUpdateCheck($db, $http, static fn (): int => $now, $updated))->runDue(static fn (): bool => false);
        Assert::same((new SelfUpdateRepository($db))->get()['status'], 'current');
        Assert::false(\Tablo\SelfUpdatePresentation::saved(new SelfUpdateRepository($db), $updated, $now)['visible']);
    }
}

function self_update_reused_comparison(string $remote): void
{
    $db = A::database(); $http = new SelfUpdateHttp(); $now = 1000;
    $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
    $check = new SelfUpdateCheck($db, $http, static function () use (&$now): int { return $now; }, $build);
    $http->branch($build->sha); $check->runDue(static fn (): bool => false);
    $now = 22_600; $latest = str_repeat('b', times: 40); $http->branch($latest);
    if ($remote === 'missing') { $http->responses[] = \Tablo\Tests\Support\UnitFixtures::response(404); }
    if ($remote !== 'missing') { $http->comparison($remote === 'stopped' ? 'ahead' : $remote, $remote === 'behind' ? 0 : 2, in_array($remote, ['behind', 'diverged'], strict: true) ? 1 : 0); }
    $check->runDue(static fn (): bool => $remote === 'stopped' && count($http->urls) === 3);
    $row = (new SelfUpdateRepository($db))->get();
    Assert::same($http->urls, ['https://api.github.com/repos/ichinya/tablo/branches/main', 'https://api.github.com/repos/ichinya/tablo/branches/main',
        'https://api.github.com/repos/ichinya/tablo/compare/' . $build->sha . '...' . $latest]);
    Assert::same($row['status'], match ($remote) { 'ahead' => 'behind', 'behind', 'diverged' => 'force_pushed', 'missing' => 'unknown', default => 'current' });
    Assert::same($row['new_commits'], $remote === 'ahead' ? 2 : null);
    Assert::same([$row['error_code'], $row['failures'], $row['claim_token']], [null, 0, null]);
    Assert::same($row['successful_at'], $remote === 'stopped' ? 1000 : $now);
    Assert::same($row['observed_sha'], in_array($remote, ['missing', 'stopped'], strict: true) ? $build->sha : $latest);
}

function self_update_fixture_comparisons(): void
{
    require_once dirname(__DIR__) . '/FixtureGitHubResponse.php';
    require_once dirname(__DIR__) . '/FixtureGitHubBody.php';
    foreach ([['a', 'a', 'identical', 0], ['b', 'b', 'identical', 0], ['a', 'b', 'ahead', 1]] as [$base, $head, $status, $count]) {
        $path = '/repos/ichinya/tablo/compare/' . str_repeat($base, times: 40) . '...' . str_repeat($head, times: 40);
        $response = new \FixtureGitHubResponse(200);
        \FixtureGitHubBody::select($path, 'https://api.github.com' . $path, '', [], $response);
        Assert::same($response->status, 200);
        Assert::same($response->body, ['status' => $status, 'ahead_by' => $count, 'behind_by' => 0]);
        Assert::same(\Tablo\GitHubComparison::checked($response->body)->status, $status);
    }
}
