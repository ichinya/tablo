<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class MigrationProcess
{
    private mixed $process = null;
    private readonly string $stdout;
    private readonly string $stderr;

    public function __construct(private readonly TemporaryDirectory $directory, private readonly string $name, string $mode, string $database)
    {
        $this->stdout = $directory->path . '/' . $name . '.out';
        $this->stderr = $directory->path . '/' . $name . '.err';
        $command = [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', dirname(__DIR__) . '/fixtures/database-process.php',
            $mode, $database, $directory->path, $name];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->stdout, 'w'],
            2 => ['file', $this->stderr, 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($this->process)) { throw new RuntimeException('Cannot start migration process'); }
        fclose($pipes[0]);
    }

    public function awaitSignal(string $name, float $timeout = 5): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            clearstatcache();
            if (is_file($this->directory->path . '/' . $name)) { return; }
            if (!proc_get_status($this->process)['running']) { break; }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Missing migration signal ' . $name . "\n" . $this->diagnostics());
    }

    public function wait(float $timeout = 10): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $result = ['code' => $status['exitcode'], 'stdout' => file_get_contents($this->stdout),
                    'stderr' => file_get_contents($this->stderr)];
                $this->close();
                return $result;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Migration process timed out' . "\n" . $this->diagnostics());
    }

    public function diagnostics(): string
    {
        return file_get_contents($this->stdout) . "\n" . file_get_contents($this->stderr);
    }

    public function close(): void
    {
        if (!is_resource($this->process)) { return; }
        if (proc_get_status($this->process)['running']) {
            // Let the writer/paused migrator close SQLite normally before falling back to termination.
            file_put_contents($this->directory->path . '/' . $this->name . '.release', 'release');
            $deadline = microtime(true) + .5;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20_000); }
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(20_000); }
            if (proc_get_status($this->process)['running']) { proc_terminate($this->process, 9); }
        }
        proc_close($this->process);
        $this->process = null;
    }
}
