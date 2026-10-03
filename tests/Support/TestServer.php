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

    public function __construct(TemporaryDirectory $directory, string $router, ?string $documentRoot = null, array $environment = [])
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) { throw new RuntimeException('Cannot allocate test port: ' . $error); }
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->base = 'http://127.0.0.1:' . $this->port;
        $this->log = $directory->path . '/server.log';
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->port];
        if ($documentRoot !== null) { array_push($command, '-t', $documentRoot); }
        $command[] = $router;
        try {
            $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'],
                2 => ['file', $this->log, 'a']], $pipes, dirname(__DIR__, 2), array_replace(getenv(), $environment));
            if (!is_resource($this->process)) { throw new RuntimeException('Cannot start test server'); }
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                if (!proc_get_status($this->process)['running']) { break; }
                $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, .1);
                if ($connection !== false) { fclose($connection); return; }
                usleep(50000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Test server did not become ready');
        } catch (\Throwable $error) {
            $this->close();
            throw new RuntimeException($error->getMessage() . "\n" . $this->diagnostics(), 0, $error);
        }
    }

    public function diagnostics(): string
    {
        return is_file($this->log) ? file_get_contents($this->log) : '(no server log)';
    }

    public function close(): void
    {
        if (!is_resource($this->process)) { return; }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20000); }
            if (proc_get_status($this->process)['running']) { proc_terminate($this->process, 9); }
        }
        proc_close($this->process);
        $this->process = null;
    }
}
