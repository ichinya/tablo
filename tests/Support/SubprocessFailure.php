<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class SubprocessFailure
{
    public static function raise(SubprocessState $state, string $stage, Throwable $cause): never
    {
        $state->event('failure', ['stage' => $stage, 'type' => $cause::class]);
        $state->directory->retainFailure($cause);
        SubprocessClosure::close($state, $cause);
        $message = match ($stage) {
            'spawn' => 'Cannot start test command', 'input' => 'Cannot write test command input',
            'selection' => 'Password selection barrier failed', default => 'Test command timed out',
        };
        throw new RuntimeException($message . '; stage=' . $stage . '; ' . SubprocessDiagnostic::describe($state));
    }
}
