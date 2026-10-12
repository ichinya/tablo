<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\IncidentFixtures as F;
use Testo\Assert;
use Testo\Test;

final class IncidentPublicTest
{
    #[Test]
    public function realManualOneShotCliAndWorkerNetworkPersistenceShareOneIncidentAndRecovery(): void
    {
        $directory = new TemporaryDirectory('tablo-incidents-public-');
        $child = null; $server = null; $web = null;
        try {
            file_put_contents($directory->path . '/health-status', data: '503');
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/incident-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $web = new WebFixture(true);
            $csrf = $web->authenticate();
            $input = array_replace(F::site(), ['_csrf' => $csrf, 'name' => 'Neutral service', 'url' => $server->base,
                'repository' => 'fixture/public', 'health_path' => '/up', 'enabled' => '1']);
            Assert::same($web->request('/sites/new', $input)['status'], 303);
            Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            $db = $web->database();
            $opening = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
            Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($opening)['id'], 1);
            Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($opening)['health_http_status'], 503);
            $root = dirname(__DIR__, levels: 2);
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/FixtureCliGitHubConnection.php',
                $root . '/bin/check.php'], $web->directory, ['TABLO_DB' => $web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            Assert::same($result['exit_code'], 1);
            Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), $opening, 'repeat CLI Offline preserves identity and opening reason');
            file_put_contents($directory->path . '/health-status', data: '200');
            $child = new WorkerProcess($directory, 'incident-worker', [PHP_BINARY, $root . '/tests/fixtures/worker-process.php'],
                ['TABLO_DB' => $web->directory->path . '/test.sqlite', 'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            $state = new WorkerStateRepository($db);
            Assert::true($state->requestStop(\Tablo\Tests\Support\FixtureValue::string($state->generation())));
            Assert::same($child->wait()['exit_code'], 0);
            $recovered = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
            Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($recovered)['id'], 1);
            Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($recovered)['recovery_history_id'], 3);
            Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($recovered)['end_reason'], 'recovered');
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT last_history_id FROM incident_checkpoints'))->fetchColumn(), 3);
            Assert::true(min($state->turns(1, 0)) > 0, 'actual worker admitted credit committed');
            file_put_contents($directory->path . '/health-status', data: '503');
            Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            Assert::same(array_column(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT id FROM incidents ORDER BY id'))->fetchAll(), \Tablo\SqlSitesIdRow::checked(...)), 'id'), [1, 4]);
        } finally {
            $child?->close(); $server?->close();
            unset($state, $db); $web?->close(); $directory->close();
        }
    }
}
