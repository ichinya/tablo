<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class WorkerProcess
{
    private readonly WorkerObservation $status;
    private readonly string $stdout;
    private readonly string $stderr;


    /** @param list<string> $command */
    public function __construct(TemporaryDirectory $directory, string $name, array $command, array $environment, ?string $cwd = null)
    {
        $this->stdout = $directory->path . '/' . $name . '.out';
        $this->stderr = $directory->path . '/' . $name . '.err';
        $this->status = new WorkerObservation();
        $pipes = [];
        if ($cwd === '') { throw new RuntimeException('Empty fixture working directory'); }
        $this->status->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->stdout, 'w'],
            2 => ['file', $this->stderr, 'w']], $pipes, $cwd ?? dirname(__DIR__, levels: 2),
            FixtureSpawn::environment($environment), ['bypass_shell' => true]);
        if (!is_resource($this->status->process)) { throw new RuntimeException('Cannot start owned worker fixture.'); }
        fclose(\Tablo\Tests\Support\FixturePipes::pipe($pipes, 0));
    }



    /** @return array{stdout: string, stderr: string} */
    public function output(): array
    {
        return WorkerStreams::read($this->stdout, $this->stderr);
    }

    public function awaitOutput(string $text, float $timeout = 8): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            if (str_contains($this->output()['stdout'], $text)) { return; }
            if (!$this->status->running()) { break; }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Worker readiness boundary failed.');
    }

    /** @return array{exit_code: ?int, stdout: string, stderr: string} */
    public function wait(float $timeout = 8): array
    {
        $deadline = microtime(true) + $timeout;
        while ($this->status->running() && microtime(true) < $deadline) { usleep(20_000); }
        if ($this->status->running()) { throw new RuntimeException('Owned worker did not exit within harness bound.'); }
        $result = ['exit_code' => $this->status->exit] + $this->output();
        $this->close();
        return $result;
    }

    /** @return array{exit_code: ?int, stdout: string, stderr: string} */
    public function terminate(int $signal = 9): array
    {
        if ($this->status->running()) { proc_terminate(\Tablo\Tests\Support\FixtureNative::resource($this->status->process), $signal); }
        return $this->wait();
    }

    public function close(): void
    {
        if (!is_resource($this->status->process)) { return; }
        if ($this->status->running()) { proc_terminate($this->status->process, signal: 9); }
        $deadline = microtime(true) + 3;
        while ($this->status->running() && microtime(true) < $deadline) { usleep(20_000); }
        if ($this->status->running()) { throw new RuntimeException('Owned child still running; preserve fixture.'); }
        proc_close($this->status->process);
        $this->status->process = null;
    }
    public function running(): bool { return $this->status->running(); }
}
