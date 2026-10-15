<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class RedirectServer
{
    public readonly TemporaryDirectory $directory;
    public readonly string $base;
    private mixed $process = null;

    public function __construct()
    {
        $this->directory = new TemporaryDirectory('tablo-redirect-');
        try {
            $this->process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/redirect-server.php', $this->directory->path],
                [0 => ['pipe', 'r'], 1 => ['file', $this->directory->path . '/peer.log', 'a'],
                    2 => ['file', $this->directory->path . '/peer.log', 'a']], $pipes);
            if (!is_resource($this->process)) { throw new \RuntimeException('Cannot start owned redirect peer'); }
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                clearstatcache();
                if (is_file($this->directory->path . '/ready')) {
                    $this->base = 'http://' . file_get_contents($this->directory->path . '/ready');
                    return;
                }
                if (!proc_get_status($this->process)['running']) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('Owned redirect peer did not become ready');
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function rows(): array
    {
        $file = $this->directory->path . '/requests.jsonl';
        return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
            file($file, FILE_IGNORE_NEW_LINES)) : [];
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            file_put_contents($this->directory->path . '/stop', '1');
            $deadline = microtime(true) + 3;
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($status['running']) { throw new \RuntimeException('Owned redirect peer remains live; retain fixture'); }
            proc_close($this->process);
            $this->process = null;
        }
        if (isset($this->base)) {
            $probe = stream_socket_server('tcp://' . substr($this->base, 7), $errno, $error);
            if ($probe === false) { throw new \RuntimeException('Owned redirect port is not reusable; retain fixture'); }
            fclose($probe);
        }
        $this->directory->close();
    }
}
