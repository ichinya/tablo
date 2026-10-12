<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class SubprocessLaunch
{
    public static function launch(SubprocessState $state, array $command, array $environment, ?string $cwd): void
    {
        $name = $state->directory->path . '/command-' . bin2hex(random_bytes(12));
        $state->files = ['stdout' => $name . '.out', 'stderr' => $name . '.err', 'receipt' => $name . '.json'];
        foreach (['stdout', 'stderr'] as $stream) {
            $file = fopen($state->files[$stream], mode: 'x');
            if ($file === false) { throw new RuntimeException('Cannot reserve test command stream'); }
            fclose($file);
            chmod($state->files[$stream], permissions: 0o600);
        }
        $state->event('spawn', ['argument_count' => count($command)]); // no argv, environment or input values
        try {
            set_error_handler(static function (): never { throw new RuntimeException('Test command spawn failed'); });
            try { $state->process = proc_open($command, [0 => ['pipe', 'r'],
                1 => ['file', $state->files['stdout'], 'a'],
                2 => $state->stderr === StderrCapture::ClosedReader ? ['pipe', 'w'] : ['file', $state->files['stderr'], 'a']],
                $pipes, $cwd ?? dirname(__DIR__, levels: 2), array_replace(getenv(), $environment), ['bypass_shell' => true]); }
            finally { restore_error_handler(); }
            if (!is_resource($state->process)) { throw new RuntimeException('Cannot start test command'); }
            $state->input = $pipes[0];
            $state->directory->holdProcess();
            $state->running = true;
            if ($state->stderr === StderrCapture::ClosedReader) {
                // The fixture waits for stdin before any diagnostic write. Closing
                // this OS pipe reader before send() is deterministic on both hosts.
                fclose($pipes[2]);
                $state->event('stderr-reader-closed-before-input');
            }
            SubprocessObservation::running($state);
        } catch (Throwable $error) { SubprocessFailure::raise($state, 'spawn', $error); }

    }
}
