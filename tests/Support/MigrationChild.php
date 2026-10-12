<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
use RuntimeException;

final class MigrationChild
{

    public mixed $process = null;
    public readonly string $stdout;
    public readonly string $stderr;
    public mixed $input = null;
    public ?int $exitCode = null;
    public array $receipt;

    public function __construct(public readonly TemporaryDirectory $directory, public readonly string $name, string $mode, string $database,
        array $schedule = [])
    {
        $this->stdout = $directory->path . '/' . $name . '.out';
        $this->stderr = $directory->path . '/' . $name . '.err';
        $command = [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $schedule['entry'] ?? dirname(__DIR__) . '/fixtures/database-process.php',
            $mode, $database, $directory->path, $name, ...($schedule['arguments'] ?? [])];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->stdout, 'w'],
            2 => ['file', $this->stderr, 'w']], $pipes, dirname(__DIR__, levels: 2), env_vars: null, options: ['bypass_shell' => true]);
        if (!is_resource($this->process)) { throw new RuntimeException('Cannot start migration process'); }
        $this->input = $pipes[0]; // EOF lets a holder detect a vanished parent.
        $this->receipt = ['argv' => $command, 'cwd' => dirname(__DIR__, levels: 2), 'startedUtc' => gmdate('c'),
            'pid' => proc_get_status($this->process)['pid']];
    }
    public function running(): bool
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
        file_put_contents($this->directory->path . '/' . $this->name . '.release', data: 'release');
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
    public function diagnostics(): string
    {
        return file_get_contents($this->stdout) . "\n" . file_get_contents($this->stderr);
    }
}
