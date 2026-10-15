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
            $this->process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/raw-http-server.php', $this->directory->path],
                [0 => ['pipe', 'r'], 1 => ['file', $this->directory->path . '/server.log', 'a'],
                    2 => ['file', $this->directory->path . '/server.log', 'a']], $pipes);
            if (!is_resource($this->process)) { throw new RuntimeException('Cannot start raw HTTP fixture'); }
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                clearstatcache();
                if (is_file($this->directory->path . '/ready')) {
                    $this->base = 'http://' . file_get_contents($this->directory->path . '/ready');
                    return;
                }
                if (!proc_get_status($this->process)['running']) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Raw HTTP fixture did not become ready');
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            file_put_contents($this->directory->path . '/stop', '1');
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20000); }
            if (proc_get_status($this->process)['running']) { proc_terminate($this->process, 9); }
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20000); }
            if (proc_get_status($this->process)['running']) { throw new RuntimeException('Raw HTTP fixture did not stop'); }
            proc_close($this->process);
            $this->process = null;
        }
        $this->directory->close();
    }
}
