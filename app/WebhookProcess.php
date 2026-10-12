<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Status array{running: bool, exitcode: int, signaled: bool, termsig: int, pid?: int, command?: string, stopped?: bool, stopsig?: int} */
final class WebhookProcess
{
    /** @var resource|false|null */
    public mixed $process = null;
    /** @var array<int, resource> */
    public array $pipes = [];
    /** @var Status|null */
    public ?array $status = null;

    /** @return resource */
    public function owned(): mixed
    {
        if (!is_resource($this->process)) { throw new WebhookFailure('child-frame'); }
        return $this->process;
    }

    /** @return resource */
    public function pipe(int $descriptor): mixed
    {
        if (!array_key_exists($descriptor, $this->pipes) || !is_resource($this->pipes[$descriptor])) { throw new WebhookFailure('child-frame'); }
        return $this->pipes[$descriptor];
    }

    /** @return Status */
    public function observed(): array
    {
        if ($this->status === null) { throw new WebhookFailure('child-frame'); }
        return $this->status;
    }
}
