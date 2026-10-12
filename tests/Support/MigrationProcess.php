<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
use RuntimeException;

final class MigrationProcess
{
    private readonly MigrationChild $state;
    public function __construct(TemporaryDirectory $directory, string $name, string $mode, string $database,
        array $schedule = [])
    { $this->state = new MigrationChild($directory, $name, $mode, $database, $schedule); }
    public function awaitSignal(string $name, float $timeout = 5): void
    {
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        do {
            clearstatcache();
            if (is_file($this->state->directory->path . '/' . $name)) { return; }
            if (!$this->state->running()) {
                // A child can publish its final acknowledgment between the file check and exit observation.
                clearstatcache();
                if (is_file($this->state->directory->path . '/' . $name)) { return; }
                break;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Missing migration signal ' . $name . "\n" . $this->state->diagnostics());
    }
    public function wait(float $timeout = 10, ?self $holder = null): array
    {
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        do {
            $holder?->assertRunning();
            if (!$this->state->running()) {
                $result = ['code' => $this->state->exitCode, 'stdout' => file_get_contents($this->state->stdout),
                    'stderr' => file_get_contents($this->state->stderr)];
                MigrationShutdown::close($this->state);
                $result['receipt'] = $this->state->receipt;
                return $result;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Migration process timed out' . "\n" . $this->state->diagnostics());
    }
    public function terminate(): array
    {
        if (!is_resource($this->state->process)) { throw new RuntimeException('Database process is already closed'); }
        if (!proc_terminate($this->state->process, signal: 9)) { throw new RuntimeException('Cannot terminate database process'); }
        return $this->wait(); // Observe exit before closing resources or removing any fixture files.
    }
    public function assertRunning(): void { $this->state->assertRunning(); }
    public function release(): void { $this->state->release(); }
    public function proceed(?string $command = 'proceed'): void { $this->state->proceed($command); }
    public function diagnostics(): string { return $this->state->diagnostics(); }
    public function close(): void { MigrationShutdown::close($this->state); }

}
