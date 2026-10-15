<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SubprocessObservation
{
    public static function running(SubprocessState $state): bool
    {
        if (!is_resource($state->process) || !$state->running) { return false; }
        $status = proc_get_status($state->process);
        $state->pid ??= $status['pid'];
        $state->running = $status['running'];
        // PHP 8.2 exposes the actual exit only on the first stopped observation.
        if (!$state->running) {
            // Windows native crash statuses can be negative signed integers. Only -1
            // means unavailable; preserve actual OS statuses instead of losing them.
            if ($status['exitcode'] !== -1) { $state->exitCode ??= $status['exitcode']; }
            $state->event('root-exit', ['exit_code' => $state->exitCode, 'native_exit_code' => $status['exitcode'], 'signal' => $status['termsig']]);
        }
        return $state->running;
    }
}
