<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class RawHttpServer
{
    public readonly TemporaryDirectory $directory;
    public readonly string $base;
    private mixed $process = null;

    public function __construct()
    {
        $this->directory = new TemporaryDirectory('tablo-framing-');
        try {
            $pipes = [];
            $this->process = FixtureSpawn::append([PHP_BINARY, dirname(__DIR__) . '/fixtures/raw-http-server.php', $this->directory->path], $this->directory->path . '/server.log', $this->directory->path . '/server.log', $pipes);
            if (!is_resource($this->process)) { throw new RuntimeException('Cannot start raw HTTP fixture'); }
            fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 0));
            $deadline = microtime(true) + 5;
            $base = null;
            do {
                clearstatcache();
                if (is_file($this->directory->path . '/ready')) {
                    $base = 'http://' . \Tablo\RequiredFile::bytes(file_get_contents($this->directory->path . '/ready'));
                    break;
                }
                if (!proc_get_status($this->process)['running']) { break; }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            if ($base === null) { throw new RuntimeException('Raw HTTP fixture did not become ready'); }
            $this->base = $base;
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            file_put_contents($this->directory->path . '/stop', data: '1');
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20_000); }
            if (proc_get_status($this->process)['running']) { proc_terminate($this->process, signal: 9); }
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20_000); }
            if (proc_get_status($this->process)['running']) { throw new RuntimeException('Raw HTTP fixture did not stop'); }
            proc_close($this->process);
            $this->process = null;
        }
        $this->directory->close();
    }
}
