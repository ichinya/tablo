<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class SubprocessCompletion
{
    public static function finish(SubprocessState $state, float $timeout = 8): array
    {
        $state->event('finish', ['timeout_seconds' => $timeout]);
        $deadline = microtime(true) + $timeout;
        do {
            if (!SubprocessObservation::running($state)) {
                SubprocessClosure::close($state, );
                return ['exit_code' => $state->exitCode, 'stdout' => file_get_contents($state->files['stdout']),
                    'stderr' => file_get_contents($state->files['stderr']), 'files' => $state->files];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        SubprocessFailure::raise($state, 'timeout', new RuntimeException('Test command timed out'));
    }
}
