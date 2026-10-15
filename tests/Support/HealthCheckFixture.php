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

    /** @return list<string> */
    protected function uris(): array
    {
        $path = \Tablo\Tests\Support\FixtureValue::object($this->endpoints)->path . '/uris.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => FixtureValue::string(json_decode($line, associative: true, depth: 4, flags: JSON_THROW_ON_ERROR)), FixtureFiles::lines($path, FILE_IGNORE_NEW_LINES)) : [];
    }

    protected function check(string $mode, string $csrf, int $cliExit): void
    {
        if ($mode === 'manual') {
            Assert::same(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            return;
        }
        $root = dirname(__DIR__, levels: 2);
        if ($mode === 'cli') {
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/FixtureCliGitHubConnection.php', $root . '/bin/check.php'],
                \Tablo\Tests\Support\FixtureValue::object($this->endpoints), ['TABLO_DB' => FixtureValue::object($this->web)->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            $diagnostic = HealthCheckDiagnostics::capture($result, \Tablo\Tests\Support\FixtureValue::object($this->web)->database());
            Assert::same($result['exit_code'], $cliExit, $diagnostic);
            Assert::same(strlen($result['stderr']), 0, $diagnostic);
            return;
        }
        $child = new WorkerProcess(\Tablo\Tests\Support\FixtureValue::object($this->endpoints), 'exact-worker', [PHP_BINARY, $root . '/tests/fixtures/worker-process.php'],
            ['TABLO_DB' => FixtureValue::object($this->web)->directory->path . '/test.sqlite', 'TABLO_WORKER_BASE' => \Tablo\Tests\Support\FixtureValue::object($this->server)->base]);
        $state = new WorkerStateRepository(\Tablo\Tests\Support\FixtureValue::object($this->web)->database());
        try {
            $child->awaitOutput('Worker pass complete.');
            $state->requestStop(\Tablo\Tests\Support\FixtureValue::string($state->generation()));
            $result = $child->wait();
            Assert::same($result['exit_code'], 0);
            Assert::same($result['stderr'], '');
        } finally { $child->close(); }
    }
}
