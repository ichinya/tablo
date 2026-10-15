<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\Subprocess;
use Testo\Assert;
use Testo\Test;

final class WorkerDashboardTest
{
    #[Test]
    public function actualWorkerResultAndTimestampReachDashboardAndManualAndOneShotRemainCompatible(): void
    {
        $web = new WebFixture(true);
        $directory = new TemporaryDirectory('tablo-worker-dashboard-endpoint-');
        $child = null; $server = null;
        try {
            $csrf = $web->authenticate();
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $path = $web->directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $id = $sites->save(array_replace(SiteInput::defaults(), ['name' => 'Worker visible fixture', 'url' => $server->base,
                'repository' => 'fixture/project', 'health_path' => '/up', 'version_path' => '/version']));
            $child = new WorkerProcess($directory, 'worker', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
                ['TABLO_DB' => $path, 'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            $state = new WorkerStateRepository($db);
            $state->requestStop($state->generation());
            Assert::same($child->wait()['exit_code'], 0);
            $workerRow = $sites->find($id);
            (new WorkerDashboardAssertions($web))->visible($workerRow);
            Assert::same($web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'], 303);
            $manual = $sites->find($id);
            $cli = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/FixtureCliGitHubConnection.php',
                dirname(__DIR__, levels: 2) . '/bin/check.php'], $directory,
                ['TABLO_DB' => $path, 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_TEST_GITHUB_LOG' => $directory->path . '/cli-calls.log']);
            Assert::same($cli['exit_code'], 0);
            $oneShot = $sites->find($id);
            WorkerDashboardAssertions::equivalent($manual, $oneShot, $workerRow);
        } finally { $child?->close(); $server?->close(); unset($state, $sites, $db); $web->close(); $directory->close(); }
    }
}
