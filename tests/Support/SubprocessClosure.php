<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class SubprocessClosure
{
    public static function close(SubprocessState $state, ?Throwable $primary = null): void
    {
        if ($state->closed) { return; }
        try {
            if (is_resource($state->input)) { fclose($state->input); }
            $state->input = null;
            if (is_resource($state->process)) {
                foreach ([15, 9] as $signal) {
                    if (!SubprocessObservation::running($state)) { break; }
                    $state->event('terminate-direct-child', ['signal' => $signal]);
                    proc_terminate($state->ownedProcess(), $signal);
                    $deadline = microtime(true) + 2;
                    while (SubprocessObservation::running($state) && microtime(true) < $deadline) { usleep(20_000); }
                }
                if (SubprocessObservation::running($state)) { throw new RuntimeException('Direct test child closure unconfirmed'); }
                $exit = proc_close($state->ownedProcess());
                $state->event('proc-close', ['native_exit_code' => $exit]);
                if ($exit !== -1) { $state->exitCode ??= $exit; }
                $state->process = null;
                $state->directory->releaseProcess();
            }
            $state->closed = true;
            $state->event('closed');
            SubprocessReceipt::write($state);
        } catch (Throwable $cleanup) {
            $primaryExists = $primary !== null || $state->directory->hasFailure();
            $state->directory->retainFailure($primary ?? $cleanup);
            $state->directory->recordCleanupFailure($cleanup);
            if (!$primaryExists) { throw $cleanup; }
        }
    }
}
