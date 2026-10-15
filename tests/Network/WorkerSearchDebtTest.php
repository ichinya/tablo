<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubConnection;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubRequestPolicy;
use Tablo\PeriodicWorker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerHttp;
use Testo\Assert;
use Testo\Test;

final class WorkerSearchDebtTest
{


    #[Test]
    public function givesBothSearchFieldsFiniteServiceWithSuccessfulCoreEveryPassAcrossRestart(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-fair-search-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment:
                ['TABLO_WORKER_FIXTURE' => $directory->path, 'TABLO_WORKER_ONE_SEARCH' => '1']);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            WorkerSearchPopulation::create($sites, $server->base);
            $http = new WorkerHttp($server->base);
            $epoch = 1_000_000;
            for ($window = 0; $window < 42; ++$window) {
                if ($window === 21) { unset($worker, $sites, $db); $db = Database::connect($path); $sites = new SiteRepository($db); }
                $http->window = $window;
                $epoch = 1_000_000 + 60 * $window;
                $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db), clock:
                    new GitHubClock(epoch: static function () use (&$epoch): int { return $epoch; }));
                $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(connection:
                    static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {}));
                $start = count($http->calls);
                $worker->runPass();
                $calls = array_slice($http->calls, $start);
                (new SearchServiceAssertions($policy, $sites))->oneWindow($calls);

            }
            $rows = $db->query('SELECT * FROM worker_progress')->fetchAll();
            Assert::same(count($rows), 21);
            foreach ($rows as $row) {
                Assert::true($row['open_issues'] > 0 && $row['open_prs'] > 0, 'both search debts paid, including later repositories');
            }
            $requests = array_map(static fn (string $line): array => json_decode($line, associative: true), file($directory->path . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
            Assert::same(count(array_filter($requests, static fn (array $row): bool => $row['path'] === '/search/issues')), 42);
        } finally { unset($worker, $policy, $sites, $db, $http); $server?->close(); $directory->close(); }
    }
}
