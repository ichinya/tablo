<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\WorkerStateRepository;
use Testo\Assert;

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

    private function endpointRows(): array
    {
        $path = $this->endpointDirectory->path . '/requests.jsonl';
        return is_file($path) ? array_values(array_filter(array_map(static fn (string $line): array => json_decode($line, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES)), static fn (array $row): bool => array_key_exists('present', $row))) : [];
    }

    private function runEndpointCheck(string $mode, int $id, string $csrf, int $cliExit = 0): void
    {
        if ($mode === 'manual') {
            Assert::same($this->web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'], 303);
            return;
        }
        if ($mode === 'cli') {
            $root = dirname(__DIR__, levels: 2);
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/FixtureCliGitHubConnection.php', $root . '/bin/check.php'],
                $this->endpointDirectory, ['TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                    'TABLO_TEST_GITHUB_LOG' => $this->web->directory->path . '/github-calls.log', 'TABLO_TEST_ENDPOINT_AUDIT' => '1']);
            Assert::same($result['exit_code'], $cliExit, HealthCheckDiagnostics::capture($result, $this->web->database()));
            Assert::same(strlen($result['stderr']), 0);
            return;
        }
        $child = new WorkerProcess($this->endpointDirectory, 'endpoint-worker', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
            ['TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_WORKER_BASE' => $this->peer->base]);
        $state = new WorkerStateRepository($this->web->database());
        try {
            $child->awaitOutput('Worker pass complete.');
            Assert::true($state->requestStop($state->generation()));
            $result = $child->wait();
            Assert::same($result['exit_code'], 0);
            Assert::same(strlen($result['stderr']), 0);
        } finally { $state = null; $child->close(); }
    }
}
