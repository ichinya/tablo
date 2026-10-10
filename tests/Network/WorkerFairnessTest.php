<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubConnection;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubRequestPolicy;
use Tablo\GitTokenRepository;
use Tablo\PeriodicWorker;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerHttp;
use Testo\Assert;
use Testo\Test;

final class WorkerFairnessTest
{
    #[Test]
    public function mixedPrimaryAndSharedSecondaryScopesKeepAllHealthAndTypedAdmissionCredit(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-fair-scopes-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $tokens = new GitTokenRepository($db);
            foreach (['core-limited','healthy','another'] as $name) {
                $token = $tokens->save(['name' => $name, 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
                $sites->save(array_replace(SiteRepository::defaults(), ['name' => $name, 'url' => $server->base,
                    'repository' => 'fixture/' . $name, 'git_token_id' => $token, 'version_path' => '/version']));
            }
            $http = new WorkerHttp($server->base);
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db));
            $worker = new PeriodicWorker($db, $http, connection: static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {});
            $worker->runPass();
            Assert::same(count(array_filter($http->calls, static fn (string $path): bool => str_starts_with($path, '/repos/fixture/core-limited/'))), 1);
            foreach ([2,3] as $id) { Assert::same($sites->find($id)['latest_release'], 'v1'); }
            $turns = (new WorkerStateRepository($db))->turns(1, 0);
            Assert::true($turns['latest_release'] > 0);
            Assert::same($turns['latest_commit'], 0, 'pre-admission primary cooldown earns no credit');
            foreach ($sites->all() as $site) { Assert::same($site['online'], 1); Assert::same($site['deployed_version'], 'v1'); }
            $input = array_replace(SiteRepository::defaults(), ['name' => 'Secondary', 'url' => $server->base,
                'repository' => 'fixture/secondary-limited', 'version_path' => '/version', 'git_token_id' => 1]);
            $sites->save($input, 1);
            $db->exec('DELETE FROM github_cooldowns');
            $start = count($http->calls);
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db));
            $worker = new PeriodicWorker($db, $http, connection: static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {});
            $worker->runPass();
            Assert::same($policy->admissions(), 1, 'one shared secondary rejection blocks all later GitHub scopes');
            Assert::same(count(array_filter(array_slice($http->calls, $start), static fn (string $path): bool => $path === '/up')), 3);
            foreach ($sites->all() as $site) { Assert::same($site['online'], 1); Assert::same($site['deployed_version'], 'v1'); }
            $start = count($http->calls);
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db));
            $worker = new PeriodicWorker($db, $http, connection: static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {});
            $worker->runPass();
            Assert::same($policy->admissions(), 0);
            Assert::same(count(array_slice($http->calls, $start)), 6, 'cooled scopes still perform health and version');
        } finally { unset($worker, $policy, $tokens, $sites, $db, $http); $server?->close(); $directory->close(); }
    }

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
            for ($i = 0; $i < 21; ++$i) {
                $sites->save(array_replace(SiteRepository::defaults(), ['name' => 'Fixture ' . $i, 'url' => $server->base,
                    'repository' => 'fixture/project-' . $i, 'version_path' => '/version']));
            }
            $http = new WorkerHttp($server->base);
            $epoch = 1000000;
            for ($window = 0; $window < 42; ++$window) {
                if ($window === 21) { unset($worker, $sites, $db); $db = Database::connect($path); $sites = new SiteRepository($db); }
                $http->window = $window;
                $epoch = 1000000 + 60 * $window;
                $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db), clock:
                    new GitHubClock(epoch: static function () use (&$epoch): int { return $epoch; }));
                $worker = new PeriodicWorker($db, $http, connection:
                    static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {});
                $start = count($http->calls);
                $worker->runPass();
                $calls = array_slice($http->calls, $start);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => $path === '/up')), 21);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => $path === '/version')), 21);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => str_starts_with($path, '/repos/'))), 42);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => str_starts_with($path, '/search/'))), 1);
                Assert::same($policy->admissions(), 43);
                foreach ($sites->all() as $site) {
                    Assert::same($site['online'], 1);
                    Assert::same($site['latest_release'], 'v1');
                    Assert::same($site['latest_commit'], str_repeat('a', 40));
                }
            }
            $rows = $db->query('SELECT * FROM worker_progress')->fetchAll();
            Assert::same(count($rows), 21);
            foreach ($rows as $row) {
                Assert::true($row['open_issues'] > 0 && $row['open_prs'] > 0, 'both search debts paid, including later repositories');
            }
            $requests = array_map(static fn (string $line): array => json_decode($line, true), file($directory->path . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
            Assert::same(count(array_filter($requests, static fn (array $row): bool => $row['path'] === '/search/issues')), 42);
        } finally { unset($worker, $policy, $sites, $db, $http); $server?->close(); $directory->close(); }
    }

    #[Test]
    public function moreThanBudgetSitesKeepFieldDebtAndIgnoreCooledCoreWithUsableSearch(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-fair-budget-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            for ($i = 0; $i < 24; ++$i) {
                $sites->save(array_replace(SiteRepository::defaults(), ['name' => 'Budget ' . $i, 'url' => $server->base,
                    'repository' => 'fixture/no-release-' . $i]));
            }
            $http = new WorkerHttp($server->base);
            for ($pass = 0; $pass < 48; ++$pass) {
                $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db), maxRequests: 5);
                $worker = new PeriodicWorker($db, $http, connection:
                    static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {});
                $worker->runPass();
                Assert::same($policy->admissions(), 5, 'one aggregate pass budget, never per-site reset');
                foreach ($sites->all() as $site) { Assert::same($site['online'], 1); }
            }
            foreach ($db->query('SELECT * FROM worker_progress')->fetchAll() as $row) {
                foreach (WorkerStateRepository::FIELDS as $field) { Assert::true($row[$field] > 0, 'every field gets admitted opportunity'); }
            }
            $storage = new GitHubCooldownRepository($db);
            $storage->defer('core', time() + 3600, 'anonymous');
            $db->exec('UPDATE worker_progress SET latest_release = 0, latest_commit = 0, open_issues = 100, open_prs = 100;
                UPDATE worker_progress SET open_prs = 0 WHERE site_id = 24');
            $policy = new GitHubRequestPolicy($storage, maxRequests: 1);
            $worker = new PeriodicWorker($db, $http, connection:
                static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {});
            $start = count($http->calls);
            $worker->runPass();
            $calls = array_slice($http->calls, $start);
            Assert::same(count(array_filter($calls, static fn (string $path): bool => str_starts_with($path, '/repos/'))), 0);
            Assert::same($sites->find(24)['open_prs'], 2, 'cooled zero core turns cannot pin early sites before usable search');
            Assert::same((new WorkerStateRepository($db))->turns(24, 0)['latest_release'], 0);
            Assert::same(count(array_filter($calls, static fn (string $path): bool => $path === '/up')), 24);
        } finally { unset($worker, $policy, $storage, $sites, $db, $http); $server?->close(); $directory->close(); }
    }
}
