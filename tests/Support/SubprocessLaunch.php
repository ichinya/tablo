<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class SubprocessLaunch
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $replacementEnvironment
     */
    public static function launch(SubprocessState $state, array $command, array $environment, ?string $cwd, #[\SensitiveParameter] ?array $replacementEnvironment = null): void
    {
        foreach (['stdout', 'stderr'] as $stream) {
            $file = fopen(\Tablo\Tests\Support\FixtureValue::at($state->files, $stream), mode: 'x');
            if ($file === false) { throw new RuntimeException('Cannot reserve test command stream'); }
            fclose($file);
            chmod(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($state->files, $stream)), permissions: 0o600);
        }
        $state->event('spawn', ['argument_count' => count($command)]); // no argv, environment or input values
        $pipes = [];
        try {
            set_error_handler(static function (): never { throw new RuntimeException('Test command spawn failed'); });
            try { $state->process = FixtureSpawn::append($command, $state->files['stdout'],
                $state->stderr === StderrCapture::ClosedReader ? null : $state->files['stderr'],
                $pipes, ['cwd' => $cwd ?? dirname(__DIR__, levels: 2), 'environment' => $replacementEnvironment ?? FixtureSpawn::environment($environment)]); }
            finally { restore_error_handler(); }
            if (!is_resource($state->process)) { throw new RuntimeException('Cannot start test command'); }
            $state->input = $pipes[0] ?? throw new RuntimeException('Missing test command input pipe');
            $state->directory->holdProcess();
            $state->running = true;
            if ($state->stderr === StderrCapture::ClosedReader) {
                // The fixture waits for stdin before any diagnostic write. Closing
                // this OS pipe reader before send() is deterministic on both hosts.
                fclose($pipes[2] ?? throw new RuntimeException('Missing test command diagnostic pipe'));
                $state->event('stderr-reader-closed-before-input');
            }
            SubprocessObservation::running($state);
            SessionEvidence::record('direct-child-start', ['pid' => $state->pid, 'sourceReceipt' => $state->files['receipt'],
                'controllerPid' => getmypid(), 'ownership' => 'direct-child-only']);
        } catch (Throwable $error) { SubprocessFailure::raise($state, 'spawn', $error); }

    }
}
