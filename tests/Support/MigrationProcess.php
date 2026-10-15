<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class MigrationProcess
{
    private mixed $process = null;
    private readonly string $stdout;
    private readonly string $stderr;
    private mixed $input = null;
    private ?int $exitCode = null;
    private array $receipt;

    public function __construct(private readonly TemporaryDirectory $directory, private readonly string $name, string $mode, string $database,
        array $schedule = [])
    {
        $this->stdout = $directory->path . '/' . $name . '.out';
        $this->stderr = $directory->path . '/' . $name . '.err';
        $command = [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $schedule['entry'] ?? dirname(__DIR__) . '/fixtures/database-process.php',
            $mode, $database, $directory->path, $name, ...($schedule['arguments'] ?? [])];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->stdout, 'w'],
            2 => ['file', $this->stderr, 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($this->process)) { throw new RuntimeException('Cannot start migration process'); }
        $this->input = $pipes[0]; // EOF lets a holder detect a vanished parent.
        $this->receipt = ['argv' => $command, 'cwd' => dirname(__DIR__, 2), 'startedUtc' => gmdate('c'),
            'pid' => proc_get_status($this->process)['pid']];
    }

    private function running(): bool
    {
        if (!is_resource($this->process)) { return false; }
        $status = proc_get_status($this->process);
        if (!$status['running'] && $this->exitCode === null) { $this->exitCode = $status['exitcode']; }
        return $status['running'];
    }

    public function assertRunning(): void
    {
        if (!$this->running()) { throw new RuntimeException('Database holder exited unexpectedly' . "\n" . $this->diagnostics()); }
    }

    public function release(): void
    {
        $this->assertRunning();
        file_put_contents($this->directory->path . '/' . $this->name . '.release', 'release');
        $this->proceed('release');
    }

    public function proceed(?string $command = 'proceed'): void
    {
        if ($command === null) {
            if (is_resource($this->input)) { fclose($this->input); }
            $this->input = null;
            return;
        }
        $this->assertRunning();
        if (!is_resource($this->input) || fwrite($this->input, $command . "\n") !== strlen($command) + 1) {
            throw new RuntimeException('Cannot command owned database process');
        }
        fflush($this->input);
    }

    public function awaitSignal(string $name, float $timeout = 5): void
    {
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        do {
            clearstatcache();
            if (is_file($this->directory->path . '/' . $name)) { return; }
            if (!$this->running()) {
                // A child can publish its final acknowledgment between the file check and exit observation.
                clearstatcache();
                if (is_file($this->directory->path . '/' . $name)) { return; }
                break;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Missing migration signal ' . $name . "\n" . $this->diagnostics());
    }

    public function wait(float $timeout = 10, ?self $holder = null): array
    {
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        do {
            $holder?->assertRunning();
            if (!$this->running()) {
                $result = ['code' => $this->exitCode, 'stdout' => file_get_contents($this->stdout),
                    'stderr' => file_get_contents($this->stderr)];
                $this->close();
                $result['receipt'] = $this->receipt;
                return $result;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Migration process timed out' . "\n" . $this->diagnostics());
    }

    public function diagnostics(): string
    {
        return file_get_contents($this->stdout) . "\n" . file_get_contents($this->stderr);
    }

    public function terminate(): array
    {
        if (!is_resource($this->process)) { throw new RuntimeException('Database process is already closed'); }
        if (!proc_terminate($this->process, 9)) { throw new RuntimeException('Cannot terminate database process'); }
        return $this->wait(); // Observe exit before closing resources or removing any fixture files.
    }

    public function close(): void
    {
        if (!is_resource($this->process)) { return; }
        $awaitExit = function (float $timeout): void {
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            do {
                if (!$this->running()) { return; }
                usleep(20_000);
            } while (hrtime(true) < $deadline);
        };
        if ($this->running()) {
            file_put_contents($this->directory->path . '/' . $this->name . '.cancel', 'cancel');
            $this->proceed(null);
            // Non-holder fixture modes retain their existing release protocol.
            file_put_contents($this->directory->path . '/' . $this->name . '.release', 'release');
            $awaitExit(.5);
        }
        if ($this->running()) {
            proc_terminate($this->process);
            $awaitExit(2);
        }
        if ($this->running()) {
            proc_terminate($this->process, 9);
            $awaitExit(2);
        }
        if ($this->running()) {
            throw new RuntimeException('Database process did not stop; preserve fixture files' . "\n" . $this->diagnostics());
        }
        proc_close($this->process);
        $this->process = null;
        $this->proceed(null);
        $this->receipt += ['endedUtc' => gmdate('c'), 'exit' => $this->exitCode, 'closeObserved' => true,
            'stdout' => ['bytes' => filesize($this->stdout), 'sha256' => hash_file('sha256', $this->stdout)],
            'stderr' => ['bytes' => filesize($this->stderr), 'sha256' => hash_file('sha256', $this->stderr)]];
        // Retain original child evidence outside the disposable scenario directory, including failed runs.
        $archive = sys_get_temp_dir() . '/tablo-database-process-evidence';
        if (!is_dir($archive) && !mkdir($archive, 0700, true)) { throw new RuntimeException('Cannot retain database process evidence'); }
        $record = $archive . '/' . bin2hex(random_bytes(8));
        if (!copy($this->stdout, $record . '.out') || !copy($this->stderr, $record . '.err')
            || file_put_contents($record . '.json', json_encode($this->receipt, JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException('Cannot retain database process streams');
        }
        if (is_file($this->directory->path . '/' . $this->name . '.events')) {
            copy($this->directory->path . '/' . $this->name . '.events', $record . '.events');
        }
    }
}
