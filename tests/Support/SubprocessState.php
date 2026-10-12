<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class SubprocessState
{

    /** @var resource|false|null */
    public mixed $process = null;
    /** @var resource|null */
    public mixed $input = null;
    public ?int $exitCode = null;
    public ?int $pid = null;
    public bool $running = false;
    public bool $closed = false;
    /** @var list<array{utc: string, stage: string, ...}> */
    public array $events = [];
    /** @var list<string> */
    public array $secrets = [];
    /** @var array{stdout: string, stderr: string, receipt: string} */
    public array $files;

    public function __construct(public readonly TemporaryDirectory $directory, public readonly StderrCapture $stderr)
    {
        $name = $directory->path . '/command-' . bin2hex(random_bytes(12));
        $this->files = ['stdout' => $name . '.out', 'stderr' => $name . '.err', 'receipt' => $name . '.json'];
    }
    /** @param array{utc?: string, stage?: string, ...} $detail */
    public function event(string $stage, array $detail = []): void
    {
        $event = ['utc' => gmdate('c'), 'stage' => $stage, ...$detail];
        $event['utc'] = FixtureValue::string($event['utc']);
        $event['stage'] = FixtureValue::string($event['stage']);
        $this->events[] = $event;
    }

    /** @return resource */
    public function ownedProcess(): mixed
    {
        if (!is_resource($this->process)) { throw new \RuntimeException('Required direct test child is unavailable'); }
        return $this->process;
    }
}
