<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** @phpstan-import-type Redirect from FixtureWireRows */
final class RedirectServer
{
    public readonly TemporaryDirectory $directory;
    public readonly string $base;
    private mixed $process = null;

    public function __construct()
    {
        $this->directory = new TemporaryDirectory('tablo-redirect-');
        try {
            $pipes = [];
            $this->process = FixtureSpawn::append([PHP_BINARY, dirname(__DIR__) . '/fixtures/redirect-server.php', $this->directory->path], $this->directory->path . '/peer.log', $this->directory->path . '/peer.log', $pipes);
            if (!is_resource($this->process)) { throw new \RuntimeException('Cannot start owned redirect peer'); }
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
                usleep(10_000);
            } while (microtime(true) < $deadline);
            if ($base === null) { throw new \RuntimeException('Owned redirect peer did not become ready'); }
            $this->base = $base;
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    /** @return list<Redirect> */
    public function rows(): array
    {
        $file = $this->directory->path . '/requests.jsonl';
        return is_file($file) ? array_map(FixtureWireRows::redirect(...),
            \Tablo\Tests\Support\FixtureValue::fetched(file($file, FILE_IGNORE_NEW_LINES))) : [];
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            file_put_contents($this->directory->path . '/stop', data: '1');
            $deadline = microtime(true) + 3;
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) { break; }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            if ($status['running']) { throw new \RuntimeException('Owned redirect peer remains live; retain fixture'); }
            proc_close($this->process);
            $this->process = null;
        }
        if (($this->base ?? null) !== null) {
            $errno = 0; $error = '';
            $probe = stream_socket_server('tcp://' . substr($this->base, offset: 7), $errno, $error);
            if ($probe === false) { throw new \RuntimeException('Owned redirect port is not reusable; retain fixture'); }
            fclose($probe);
        }
        $this->directory->close();
    }
}
