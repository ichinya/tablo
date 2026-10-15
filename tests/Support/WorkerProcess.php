<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class WorkerProcess
{
    private mixed $process = null;
    private readonly string $stdout;
    private readonly string $stderr;
    private ?int $exit = null;

    public function __construct(TemporaryDirectory $directory, string $name, array $command, array $environment, ?string $cwd = null)
    {
        $this->stdout = $directory->path . '/' . $name . '.out';
        $this->stderr = $directory->path . '/' . $name . '.err';
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->stdout, 'w'],
            2 => ['file', $this->stderr, 'w']], $pipes, $cwd ?? dirname(__DIR__, 2),
            array_replace(getenv(), $environment), ['bypass_shell' => true]);
        if (!is_resource($this->process)) { throw new RuntimeException('Cannot start owned worker fixture.'); }
        fclose($pipes[0]);
    }

    public function running(): bool
    {
        if (!is_resource($this->process) || $this->exit !== null) { return false; }
        $status = proc_get_status($this->process);
        if (!$status['running']) { $this->exit = $status['exitcode']; }
        return $status['running'];
    }

    public function output(): array
    {
        return ['stdout' => file_get_contents($this->stdout), 'stderr' => file_get_contents($this->stderr)];
    }

    public function awaitOutput(string $text, float $timeout = 8): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            if (str_contains($this->output()['stdout'], $text)) { return; }
            if (!$this->running()) { break; }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Worker readiness boundary failed.');
    }

    public function wait(float $timeout = 8): array
    {
        $deadline = microtime(true) + $timeout;
        while ($this->running() && microtime(true) < $deadline) { usleep(20000); }
        if ($this->running()) { throw new RuntimeException('Owned worker did not exit within harness bound.'); }
        $result = ['exit_code' => $this->exit] + $this->output();
        $this->close();
        return $result;
    }

    public function terminate(int $signal = 9): array
    {
        if ($this->running()) { proc_terminate($this->process, $signal); }
        return $this->wait();
    }

    public function close(): void
    {
        if (!is_resource($this->process)) { return; }
        if ($this->running()) { proc_terminate($this->process, 9); }
        $deadline = microtime(true) + 3;
        while ($this->running() && microtime(true) < $deadline) { usleep(20000); }
        if ($this->running()) { throw new RuntimeException('Owned child still running; preserve fixture.'); }
        proc_close($this->process);
        $this->process = null;
    }
}
