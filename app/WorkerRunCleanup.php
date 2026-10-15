<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerRunCleanup
{
    public function __construct(private readonly WorkerStateRepository $state) {}
    public function finishRun(?string $generation, WorkerLock $lock, ?\Throwable $failure): ?\Throwable
    {
        $primary = $failure;
        try { if ($generation !== null) { $this->state->finish($generation); } }
        catch (\Throwable $error) { if ($primary === null) { $failure = $error; } }
        try { $lock->close(); }
        catch (\Throwable $error) { if ($primary === null) { $failure = $error; } }
        return $failure;
    }
}
