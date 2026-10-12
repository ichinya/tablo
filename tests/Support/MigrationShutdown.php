<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
use RuntimeException;

final class MigrationShutdown
{
    public static function close(MigrationChild $state): void
    {
        if (!is_resource($state->process)) { return; }
        $awaitExit = static function (float $timeout) use ($state): void {
            $deadline = \Tablo\MonotonicTime::integer(hrtime(true)) + $timeout * 1_000_000_000;
            do {
                if (!$state->running()) { return; }
                usleep(20_000);
            } while (\Tablo\MonotonicTime::integer(hrtime(true)) < $deadline);
        };
        if ($state->running()) {
            file_put_contents($state->directory->path . '/' . $state->name . '.cancel', data: 'cancel');
            $state->proceed(null);
            // Non-holder fixture modes retain their existing release protocol.
            file_put_contents($state->directory->path . '/' . $state->name . '.release', data: 'release');
            $awaitExit(.5);
        }
        if ($state->running()) {
            proc_terminate($state->process);
            $awaitExit(2);
        }
        if ($state->running()) {
            proc_terminate($state->process, signal: 9);
            $awaitExit(2);
        }
        if ($state->running()) {
            throw new RuntimeException('Database process did not stop; preserve fixture files' . "\n" . $state->diagnostics());
        }
        proc_close($state->process);
        $state->process = null;
        $state->proceed(null);
        $state->receipt += ['endedUtc' => gmdate('c'), 'exit' => $state->exitCode, 'closeObserved' => true,
            'stdout' => ['bytes' => filesize($state->stdout), 'sha256' => hash_file('sha256', $state->stdout)],
            'stderr' => ['bytes' => filesize($state->stderr), 'sha256' => hash_file('sha256', $state->stderr)]];
        MigrationEvidence::retain($state);
    }
}
