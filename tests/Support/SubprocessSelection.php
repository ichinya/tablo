<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class SubprocessSelection
{
    public static function waitForOutput(SubprocessState $state, string $marker, float $timeout = 5): void
    {
        $state->event('selection', ['timeout_seconds' => $timeout]);
        $deadline = microtime(true) + $timeout;
        do {
            if (str_contains(\Tablo\RequiredFile::bytes(file_get_contents($state->files['stdout'])), $marker)) { return; }
            if (!SubprocessObservation::running($state)) { break; }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        SubprocessFailure::raise($state, 'selection', new RuntimeException('Password selection barrier failed'));
    }
}
