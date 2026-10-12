<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubRequestPolicy;
use Tablo\PeriodicWorker;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerHttp;
use Testo\Assert;
use Testo\Test;

final class WorkerBudgetDebtTest
{


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
                $sites->save(array_replace(SiteInput::defaults(), ['name' => 'Budget ' . $i, 'url' => $server->base,
                    'repository' => 'fixture/no-release-' . $i]));
            }
            $http = new WorkerHttp($server->base);
            for ($pass = 0; $pass < 48; ++$pass) {
                $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db), maxRequests: 5);
                $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(connection:
                    static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {}));
                $worker->runPass();
                Assert::same($policy->admissions(), 5, 'one aggregate pass budget, never per-site reset');
                foreach ($sites->all() as $site) { Assert::same($site['online'], 1); }
            }
            foreach (\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM worker_progress'))->fetchAll(), \Tablo\SqlWorkerProgressRow::checked(...)) as $row) {
                foreach (WorkerStateRepository::FIELDS as $field) { Assert::true(\Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($row, $field)) > 0, 'every field gets admitted opportunity'); }
            }
            $storage = new GitHubCooldownRepository($db);
            $storage->defer('core', time() + 3600, 'anonymous');
            $db->exec('UPDATE worker_progress SET latest_release = 0, latest_commit = 0, open_issues = 100, open_prs = 100;
                UPDATE worker_progress SET open_prs = 0 WHERE site_id = 24');
            $policy = new GitHubRequestPolicy($storage, maxRequests: 1);
            $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(connection:
                static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {}));
            $start = count($http->calls);
            $worker->runPass();
            $calls = array_slice($http->calls, $start);
            Assert::same(count(array_filter($calls, static fn (string $path): bool => str_starts_with($path, '/repos/'))), 0);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find(24))['open_prs'], 2, 'cooled zero core turns cannot pin early sites before usable search');
            Assert::same((new WorkerStateRepository($db))->turns(24, 0)['latest_release'], 0);
            Assert::same(count(array_filter($calls, static fn (string $path): bool => $path === '/up')), 24);
        } finally { unset($worker, $policy, $storage, $sites, $db, $http); $server?->close(); $directory->close(); }
    }
}
