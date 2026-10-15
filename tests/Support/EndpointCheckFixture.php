<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\WorkerStateRepository;
use Testo\Assert;

/** @phpstan-type EndpointRow array{path: string, present: bool, a: bool, b: bool, git: bool, ...} */
trait EndpointCheckFixture
{
    private ?WebFixture $web = null;
    private ?TemporaryDirectory $endpointDirectory = null;
    private ?TestServer $peer = null;

    private function startEndpoints(): void
    {
        $this->web = new WebFixture(true, environment: ['TABLO_TEST_ENDPOINT_AUDIT' => '1']);
        $this->endpointDirectory = new TemporaryDirectory('tablo-endpoint-runners-');
        $this->peer = new TestServer($this->endpointDirectory, dirname(__DIR__) . '/fixtures/endpoint-token-router.php', environment: [
            'TABLO_ENDPOINT_FIXTURE' => $this->endpointDirectory->path, 'TABLO_WORKER_FIXTURE' => $this->endpointDirectory->path]);
    }

    private function stopEndpoints(): void
    {
        $this->web?->close();
        $this->peer?->close();
        $this->endpointDirectory?->close();
        $this->endpointDirectory = null; $this->peer = null; $this->web = null;
    }

    /** @return list<EndpointRow> */
    private function endpointRows(): array
    {
        $path = \Tablo\Tests\Support\FixtureValue::object($this->endpointDirectory)->path . '/requests.jsonl';
        $selected = [];
        if (!is_file($path)) { return $selected; }
        foreach (FixtureFiles::lines($path, FILE_IGNORE_NEW_LINES) as $line) {
            $row = \Tablo\JsonRecord::decode($line, 8);
            if (!array_key_exists('present', $row)) { continue; }
            $row['path'] = \Tablo\SqlColumn::string($row, 'path');
            foreach (['present', 'a', 'b', 'git'] as $key) { $row[$key] = FixtureJson::boolean($row, $key); }
            $selected[] = $row;
        }
        return $selected;
    }

    private function runEndpointCheck(string $mode, int $id, string $csrf, int $cliExit = 0): void
    {
        if ($mode === 'manual') {
            Assert::same(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'], 303);
            return;
        }
        if ($mode === 'cli') {
            $root = dirname(__DIR__, levels: 2);
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/FixtureCliGitHubConnection.php', $root . '/bin/check.php'],
                \Tablo\Tests\Support\FixtureValue::object($this->endpointDirectory), ['TABLO_DB' => FixtureValue::object($this->web)->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                    'TABLO_TEST_GITHUB_LOG' => FixtureValue::object($this->web)->directory->path . '/github-calls.log', 'TABLO_TEST_ENDPOINT_AUDIT' => '1']);
            Assert::same($result['exit_code'], $cliExit, HealthCheckDiagnostics::capture($result, \Tablo\Tests\Support\FixtureValue::object($this->web)->database()));
            Assert::same(strlen($result['stderr']), 0);
            return;
        }
        $child = new WorkerProcess(\Tablo\Tests\Support\FixtureValue::object($this->endpointDirectory), 'endpoint-worker', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
            ['TABLO_DB' => FixtureValue::object($this->web)->directory->path . '/test.sqlite', 'TABLO_WORKER_BASE' => \Tablo\Tests\Support\FixtureValue::object($this->peer)->base]);
        $state = new WorkerStateRepository(\Tablo\Tests\Support\FixtureValue::object($this->web)->database());
        try {
            $child->awaitOutput('Worker pass complete.');
            Assert::true($state->requestStop(\Tablo\Tests\Support\FixtureValue::string($state->generation())));
            $result = $child->wait();
            Assert::same($result['exit_code'], 0);
            Assert::same(strlen($result['stderr']), 0);
        } finally { $state = null; $child->close(); }
    }
}
