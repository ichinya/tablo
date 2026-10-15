<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Lifecycle\AfterTest;
use Tablo\WorkerStateRepository;

trait HealthCheckFixture
{
    protected ?WebFixture $web = null;
    protected ?TemporaryDirectory $endpoints = null;
    protected ?TestServer $server = null;

    #[BeforeTest]
    public function start(): void
    {
        try {
            $this->web = new WebFixture(true);
            $this->endpoints = new TemporaryDirectory('tablo-health-endpoints-');
            $this->server = new TestServer($this->endpoints, dirname(__DIR__) . '/endpoint-router.php', environment: [
                'TABLO_HEALTH_FIXTURE' => $this->endpoints->path, 'TABLO_WORKER_FIXTURE' => $this->endpoints->path]);
        } catch (\Throwable $error) { $this->stop(); throw $error; }
    }

    #[AfterTest]
    public function stop(): void
    {
        $this->web?->close();
        $this->server?->close();
        $this->endpoints?->close();
        $this->web = null;
        $this->server = null;
        $this->endpoints = null;
    }

    protected function uris(): array
    {
        $path = $this->endpoints->path . '/uris.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => json_decode($line, true, 4, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES)) : [];
    }

    protected function check(string $mode, string $csrf, int $cliExit): void
    {
        if ($mode === 'manual') {
            Assert::same($this->web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            return;
        }
        $root = dirname(__DIR__, 2);
        if ($mode === 'cli') {
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php', $root . '/bin/check.php'],
                $this->endpoints, ['TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            $diagnostic = HealthCheckDiagnostics::capture($result, $this->web->database());
            Assert::same($result['exit_code'], $cliExit, $diagnostic);
            Assert::same(strlen($result['stderr']), 0, $diagnostic);
            return;
        }
        $child = new WorkerProcess($this->endpoints, 'exact-worker', [PHP_BINARY, $root . '/tests/fixtures/worker-process.php'],
            ['TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_WORKER_BASE' => $this->server->base]);
        $state = new WorkerStateRepository($this->web->database());
        try {
            $child->awaitOutput('Worker pass complete.');
            $state->requestStop($state->generation());
            $result = $child->wait();
            Assert::same($result['exit_code'], 0);
            Assert::same($result['stderr'], '');
        } finally { $child->close(); }
    }
}
