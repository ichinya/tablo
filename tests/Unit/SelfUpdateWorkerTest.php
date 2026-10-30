<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\PeriodicWorker;
use Tablo\SelfUpdateRepository;
use Tablo\SettingsRepository;
use Tablo\WorkerHooks;
use Tablo\Tests\Support\SelfUpdateHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

/** @phpstan-type PassResult array{updates: list<array{int, int}>, health: list<string>, sites: int, history: int, interval: int, exit: int} */
final class SelfUpdateWorkerTest
{
    #[Test]
    public function dueCheckRunsBetweenSitesDuringAnEightHourPassWithoutStarvingSites(): void
    {
        $result = self_update_worker_environment(static fn (): array => self_update_long_pass());
        Assert::same($result['updates'], [[1000, 0], [22_600, 3]], 'six-hour due check runs before the fourth site, not after the eight-hour pass');
        Assert::same($result['health'], ['https://synthetic.invalid/site-1/up', 'https://synthetic.invalid/site-2/up', 'https://synthetic.invalid/site-3/up', 'https://synthetic.invalid/site-4/up']);
        Assert::same([$result['exit'], $result['sites'], $result['history'], $result['interval']], [0, 4, 4, 10]);
    }

    #[Test]
    public function stopBetweenSitesSkipsBothDueUpdateAndTheNextSite(): void
    {
        $result = self_update_worker_environment(static fn (): array => self_update_long_pass(stopAfter: 3));
        Assert::same($result['updates'], [[1000, 0]]);
        Assert::same([$result['exit'], count($result['health']), $result['sites'], $result['history']], [0, 3, 3, 3]);
    }

    #[Test]
    public function storageAndUnexpectedRuntimeFailuresEscapeBeforeSiteNetworkOrRetryWrites(): void
    { self_update_worker_environment(self_update_worker_fatal_boundaries(...)); }

    #[Test]
    public function confirmedTransportAndGitHubFailuresStillAllowSiteMonitoring(): void
    { self_update_worker_environment(self_update_worker_network_isolated(...)); }
    #[Test]
    public function pollsUpdateDueIndependentlyWithNoSitesAndOneDaySiteInterval(): void
    {
        $directory = new TemporaryDirectory('tablo-self-update-worker-'); $environment = [];
        foreach (['TABLO_BUILD_REPOSITORY', 'TABLO_BUILD_BRANCH', 'TABLO_BUILD_SHA'] as $name) { $environment[$name] = getenv($name); }
        try {
            putenv('TABLO_BUILD_REPOSITORY=ichinya/tablo'); putenv('TABLO_BUILD_BRANCH=main'); putenv('TABLO_BUILD_SHA=' . str_repeat('a', times: 40));
            $db = Database::connect($directory->path . '/db.sqlite'); (new SettingsRepository($db))->updateInterval('1440');
            $http = new SelfUpdateHttp(); $http->branch(str_repeat('b', times: 40)); $http->comparison('ahead', ahead: 1);
            $http->branch(str_repeat('b', times: 40)); $http->comparison('ahead', ahead: 1);
            $time = 1000.0; $worker = null;
            $hooks = new WorkerHooks(clock: static function () use (&$time): float { return $time; },
                sleep: static function (float $seconds) use (&$time, &$worker, $http): void { $time += $seconds * 100_000; if (count($http->urls) === 4) { $worker?->stop(); } },
                output: static function (string $message): void {}, epoch: static function () use (&$time): int { return (int) $time; });
            $worker = new PeriodicWorker($db, $http, $hooks); Assert::same($worker->run(), 0);
            Assert::same(count($http->urls), 4); Assert::true($time < 87_400, 'self-check ran during first site wait');
            Assert::same((new SelfUpdateRepository($db))->get()['status'], 'behind');
        } finally {
            foreach ($environment as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
            unset($worker, $hooks, $http, $db); gc_collect_cycles(); $directory->close();
        }
    }
}

/** @template T
 * @param \Closure(): T $operation
 * @return T */
function self_update_worker_environment(\Closure $operation): mixed
{
    $environment = [];
    foreach (['TABLO_BUILD_REPOSITORY', 'TABLO_BUILD_BRANCH', 'TABLO_BUILD_SHA'] as $name) { $environment[$name] = getenv($name); }
    try {
        putenv('TABLO_BUILD_REPOSITORY=ichinya/tablo'); putenv('TABLO_BUILD_BRANCH=main'); putenv('TABLO_BUILD_SHA=' . str_repeat('a', times: 40));
        return $operation();
    } finally { foreach ($environment as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); } }
}

/** @return \Tablo\Tests\Unit\SelfUpdateWorkerTest::PassResult */
function self_update_long_pass(?int $stopAfter = null): array
{
    $directory = new TemporaryDirectory('tablo-self-update-long-pass-');
    try {
        $db = Database::connect($directory->path . '/db.sqlite'); $sites = new \Tablo\SiteRepository($db);
        for ($id = 1; $id <= 4; $id++) {
            $sites->save([...\Tablo\Tests\Support\UnitFixtures::site(), 'name' => 'Serial ' . $id,
                'url' => 'https://synthetic.invalid/site-' . $id, 'repository' => 'synthetic/site', 'version_path' => '']);
        }
        $http = new \Tablo\Tests\Support\SelfUpdateWorkerHttp(); $worker = null;
        if ($stopAfter !== null) { $http->afterHealth = static function (int $completed) use (&$worker, $stopAfter): void { if ($completed === $stopAfter) { $worker?->stop(); } }; }
        $hooks = new WorkerHooks(clock: static fn (): float => (float) $http->epoch, epoch: static fn (): int => $http->epoch,
            output: static function (string $message) use (&$worker): void { if ($message === 'Worker pass complete.') { $worker?->stop(); } });
        $worker = new PeriodicWorker($db, $http, $hooks); $exit = $worker->run();
        return ['updates' => $http->updates, 'health' => $http->health,
            'sites' => (int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL'))->fetchColumn(),
            'history' => (int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(),
            'interval' => (new SettingsRepository($db))->get(), 'exit' => $exit];
    } finally { unset($worker, $hooks, $http, $sites, $db); gc_collect_cycles(); $directory->close(); }
}

function self_update_worker_fatal_boundaries(): void
{
    foreach (['claim', 'policy', 'branch', 'settle'] as $stage) {
        foreach ([new \PDOException('synthetic-storage'), new \Tablo\DatabaseFailure('schema'), new \Tablo\SharedKeyFailure('synthetic-key')] as $failure) {
            self_update_worker_fatal_case($stage, $failure);
        }
    }
    self_update_worker_fatal_case('clock', new \RuntimeException('synthetic-clock'));
    self_update_worker_fatal_case('compare', new \LogicException('synthetic-runtime'));
    self_update_worker_fatal_case('branch', new \RuntimeException('synthetic-runtime'));
}

function self_update_worker_fatal_case(string $stage, \Throwable $failure): void
{
    $directory = new TemporaryDirectory('tablo-self-update-fatal-');
    try {
        $db = new FailureBoundaryPdo(':memory:'); Database::migrate($db); $vault = new \Tablo\TokenVault($directory->path . '/key');
        $sites = new \Tablo\SiteRepository($db, $vault); $sites->save([...\Tablo\Tests\Support\UnitFixtures::site(), 'version_path' => '']);
        $branch = \Tablo\Tests\Support\UnitFixtures::response(200, '{"name":"main","commit":{"sha":"' . str_repeat($stage === 'compare' ? 'b' : 'a', times: 40) . '"}}');
        $http = new \Tablo\Tests\Support\FakeHttp($stage === 'branch' ? [$failure] : [$branch, $failure]); $logs = [];
        $hooks = new WorkerHooks(epoch: static fn (): int => $stage === 'clock' ? throw $failure : 1000,
            output: static function (string $message) use (&$logs): void { $logs[] = $message; });
        $fragment = match ($stage) { 'claim' => 'SELECT * FROM self_update', 'policy' => 'SELECT MAX', 'settle' => 'UPDATE self_update SET observed_sha', default => '' };
        if ($fragment !== '') { $db->arm($failure, $fragment); }
        $worker = new PeriodicWorker($db, $http, $hooks, $vault); $caught = null;
        try { $worker->runPass(); } catch (\Throwable $error) { $caught = $error; unset($error); }
        $db->disarm(); Assert::same($caught, $failure); Assert::same($logs, []);
        Assert::same(count($http->requests), match ($stage) { 'settle', 'branch' => 1, 'compare' => 2, default => 0 });
        $row = (new SelfUpdateRepository($db))->get(); Assert::same([$row['failures'], $row['error_code'], $row['successful_at']], [0, null, null]);
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 0);
    } finally { unset($caught, $worker, $hooks, $http, $sites, $vault, $db); gc_collect_cycles(); $directory->close(); }
}

function self_update_worker_network_isolated(): void
{
    foreach ([new \Tablo\HttpFailure('timeout'), \Tablo\Tests\Support\UnitFixtures::response(503)] as $failure) {
        $directory = new TemporaryDirectory('tablo-self-update-transport-');
        try {
            $db = Database::connect($directory->path . '/db.sqlite'); $sites = new \Tablo\SiteRepository($db);
            $sites->save([...\Tablo\Tests\Support\UnitFixtures::site(), 'version_path' => '']);
            $response = \Tablo\Tests\Support\UnitFixtures::response(...);
            $http = new \Tablo\Tests\Support\FakeHttp([$failure, $response(), $response(status: 200, body: '{"tag_name":"v1"}'),
                $response(status: 200, body: '{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}'),
                $response(status: 200, body: '{"total_count":0,"incomplete_results":false}'), $response(status: 200, body: '{"total_count":0,"incomplete_results":false}')]);
            $worker = new PeriodicWorker($db, $http, new WorkerHooks(epoch: static fn (): int => 1000, output: static function (string $message): void {}));
            $worker->runPass();
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM sites WHERE online = 1'))->fetchColumn(), 1);
            $row = (new SelfUpdateRepository($db))->get(); Assert::same([$row['failures'], $row['error_code'], $row['next_due']], [1, 'unavailable', 1300]);
            Assert::same(count($http->endpointRequests), 1);
        } finally { unset($worker, $http, $sites, $db); gc_collect_cycles(); $directory->close(); }
    }
}
