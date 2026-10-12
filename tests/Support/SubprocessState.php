<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class SubprocessState
{

    public mixed $process = null;
    public mixed $input = null;
    public ?int $exitCode = null;
    public ?int $pid = null;
    public bool $running = false;
    public bool $closed = false;
    public array $events = [];
    public array $secrets = [];
    public array $files;

    public function __construct(public readonly TemporaryDirectory $directory, public readonly StderrCapture $stderr) {}
    public function event(string $stage, array $detail = []): void
    {
        $this->events[] = ['utc' => gmdate('c'), 'stage' => $stage, ...$detail];
    }
}
