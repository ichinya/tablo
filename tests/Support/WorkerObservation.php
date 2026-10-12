<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class WorkerObservation
{
    public mixed $process = null;
    public ?int $exit = null;
    public function running(): bool
    {
        if (!is_resource($this->process) || $this->exit !== null) { return false; }
        $status = proc_get_status($this->process);
        if (!$status['running']) { $this->exit = $status['exitcode']; }
        return $status['running'];
    }
}
