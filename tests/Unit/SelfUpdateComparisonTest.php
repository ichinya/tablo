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
