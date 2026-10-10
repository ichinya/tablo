<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Database;
use Tablo\SiteRepository;
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
        $server = $child = null;
        try {
            $csrf = $web->authenticate();
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $path = $web->directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $id = $sites->save(array_replace(SiteRepository::defaults(), ['name' => 'Worker visible fixture', 'url' => $server->base,
                'repository' => 'fixture/project', 'health_path' => '/up', 'version_path' => '/version']));
            $child = new WorkerProcess($directory, 'worker', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
                ['TABLO_DB' => $path, 'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            $state = new WorkerStateRepository($db);
            $state->requestStop($state->generation());
            Assert::same($child->wait()['exit_code'], 0);
            $workerRow = $sites->find($id);
            Assert::same($workerRow['online'], 1);
            Assert::true($workerRow['checked_at'] !== null);
            $page = $web->request('/');
            Assert::same($page['status'], 200);
            Assert::true(str_contains($page['body'], 'Worker visible fixture') && str_contains($page['body'], 'Online'));
            Assert::true(str_contains($page['body'], gmdate('d.m.Y H:i', strtotime($workerRow['checked_at'])) . ' UTC'));
            Assert::same($web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'], 303);
            $manual = $sites->find($id);
            $cli = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/cli-github-fixture.php',
                dirname(__DIR__, 2) . '/bin/check.php'], $directory,
                ['TABLO_DB' => $path, 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_TEST_GITHUB_LOG' => $directory->path . '/cli-calls.log']);
            Assert::same($cli['exit_code'], 0);
            $oneShot = $sites->find($id);
            foreach (['online','deployed_version','deployed_commit','latest_release','latest_commit','open_issues','open_prs','last_error'] as $field) {
                Assert::same($manual[$field], $oneShot[$field], 'manual/actual bin check equality');
            }
            Assert::same($oneShot['online'], $workerRow['online']);
            Assert::same($oneShot['open_issues'], $workerRow['open_issues']);
            Assert::false(array_key_exists('worker_service', $oneShot));
        } finally { $child?->close(); $server?->close(); unset($state, $sites, $db); $web->close(); $directory->close(); }
    }
}
