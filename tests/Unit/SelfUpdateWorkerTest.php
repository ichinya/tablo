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

final class SelfUpdateWorkerTest
{
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
