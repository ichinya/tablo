<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SubprocessObservation
{
    /** @return array{observed: bool, signaled: bool, term_signal: ?int, proc_close: ?int} */
    public static function stopped(SubprocessState $state): array
    { return subprocess_observation_stopped($state); }

    public static function running(SubprocessState $state): bool
    {
        if (!is_resource($state->process) || !$state->running) { return false; }
        $status = proc_get_status($state->process);
        $first = $state->evidence?->lastStatus === null;
        if ($state->evidence !== null) {
            $state->evidence->lastStatus = ['pid' => $status['pid'], 'running' => $status['running'],
                'signaled' => $status['signaled'], 'stopped' => $status['stopped'],
                'exitcode' => $status['exitcode'], 'termsig' => $status['termsig'], 'stopsig' => $status['stopsig']];
            $raw = \Tablo\JsonRecord::checked($status);
            if (array_key_exists('cached', $raw)) { $state->evidence->lastStatus['cached'] = FixtureValue::boolean($raw['cached']); }
        }
        $state->pid ??= $status['pid'];
        $state->running = $status['running'];
        if ($first && $state->evidence !== null) { $state->event('direct-status-after-launch'); }
        // PHP 8.2 exposes the actual exit only on the first stopped observation.
        if (!$state->running) {
            // Windows native crash statuses can be negative signed integers. Only -1
            // means unavailable; preserve actual OS statuses instead of losing them.
            if ($status['exitcode'] !== -1 && !$status['signaled']) { $state->exitCode ??= $status['exitcode']; }
            $state->event('root-exit', ['exit_code' => $state->exitCode, 'native_exit_code' => $status['exitcode'], 'signal' => $status['termsig'], 'signaled' => $status['signaled']]);
        }
        return $state->running;
    }


}

/** Derive lossless facts from actual native events; do not add a second mutable process status.
 * @return array{observed: bool, signaled: bool, term_signal: ?int, proc_close: ?int} */
function subprocess_observation_stopped(SubprocessState $state): array
{
    $observed = false; $signaled = false; $signal = null; $closed = null;
    foreach ($state->events as $event) {
        if ($event['stage'] === 'root-exit' && !$observed) {
            $observed = true; $signaled = FixtureValue::boolean($event['signaled'] ?? null);
            $native = FixtureValue::integer($event['signal'] ?? null); $signal = $signaled && $native > 0 ? $native : null;
        }
        if ($event['stage'] === 'proc-close') { $closed = FixtureValue::integer($event['native_exit_code'] ?? null); }
    }
    return ['observed' => $observed, 'signaled' => $signaled, 'term_signal' => $signal, 'proc_close' => $closed];
}
