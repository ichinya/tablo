<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class SubprocessCompletion
{
    /** @return array{exit_code: ?int, stdout: string, stderr: string, files: array{stdout: string, stderr: string, receipt: string}} */
    public static function finish(SubprocessState $state, float $timeout = 8): array
    {
        $state->event('finish', ['timeout_seconds' => $timeout]);
        $deadline = microtime(true) + $timeout;
        do {
            if (!SubprocessObservation::running($state)) {
                SubprocessClosure::close($state, );
                $stdout = file_get_contents($state->files['stdout']);
                $stderr = file_get_contents($state->files['stderr']);
                if ($stdout === false || $stderr === false) { throw new RuntimeException('Cannot read closed test command streams'); }
                return ['exit_code' => $state->exitCode, 'stdout' => $stdout,
                    'stderr' => $stderr, 'files' => $state->files];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        SubprocessFailure::raise($state, 'timeout', new RuntimeException('Test command timed out'));
    }
}
