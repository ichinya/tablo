<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class TestServer
{
    public readonly int $port;
    public readonly string $base;
    private mixed $process = null;
    private readonly string $log;
    private readonly string $errorLog;
    private readonly string $stderr;
    /** @var list<string> */
    private readonly array $command;
    private int $pid = 0;

    public function __construct(TemporaryDirectory $directory, string $router, ?string $documentRoot = null,
        array $environment = [], string $host = '127.0.0.1')
    {
        $socketHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $this->port = ServerPort::allocate($socketHost);
        $this->base = 'http://' . $socketHost . ':' . $this->port;
        $this->log = $directory->path . '/server.log';
        $this->stderr = $directory->path . '/server.stderr';
        // Keep PHP's independent error-log writer separate from proc_open streams.
        $this->errorLog = $directory->path . '/php-errors.log';
        $command = ServerCommand::create($router, $documentRoot, $socketHost . ':' . $this->port, $this->errorLog);
        $this->command = $command;
        try {
            $pipes = [];
            $this->process = FixtureSpawn::append($command, $this->log, $this->stderr, $pipes, ['cwd' => dirname(__DIR__, levels: 2), 'environment' => FixtureSpawn::environment($environment)]);
            if (!is_resource($this->process)) { throw new RuntimeException('Cannot start test server'); }
            $this->pid = proc_get_status($this->process)['pid'];
            $this->recordStart($directory, $environment);
            fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 0));
            $deadline = microtime(true) + 5;
            do {
                if (!proc_get_status(FixtureNative::resource($this->process))['running']) { break; }
                $connection = \Tablo\NativeWarning::ignore(function () use (&$socketHost, &$errno, &$error) { return fsockopen($socketHost, $this->port, $errno, $error, timeout: .1); });
                if ($connection !== false) { fclose($connection); return; }
                usleep(50_000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Test server did not become ready');
        } catch (\Throwable $error) {
            $this->close();
            throw new RuntimeException($error->getMessage() . "\n" . $this->diagnostics(), 0, $error);
        }
    }

    public function diagnostics(): string
    {
        return (is_file($this->log) ? \Tablo\RequiredFile::bytes(file_get_contents($this->log)) : '(no server log)')
            . (is_file($this->stderr) ? "\n" . \Tablo\RequiredFile::bytes(file_get_contents($this->stderr)) : '')
            . (is_file($this->errorLog) ? "\n" . \Tablo\RequiredFile::bytes(file_get_contents($this->errorLog)) : '');
    }

    private function recordStart(TemporaryDirectory $directory, array $environment): void
    {
        SessionEvidence::record('server-start', ['pid' => $this->pid, 'argv' => $this->command, 'cwd' => dirname(__DIR__, levels: 2),
            'environmentKeys' => array_keys($environment), 'fixture' => $directory->path]);
    }

    public function close(): void
    {
        if (!is_resource($this->process)) { return; }
        $closed = ServerClosure::close($this->process);
        $this->process = null;
        SessionEvidence::server(['argv' => $this->command, 'pid' => $this->pid, 'errorLog' => $this->errorLog, ...$closed], $this->log, $this->stderr);
    }
}
