<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class SubprocessInput
{
    /** Interactive actors need multiple releases; the one-shot input operation below still closes stdin. */
    public static function barrier(SubprocessState $state, #[\SensitiveParameter] string $input): void
    {
        $lines = preg_split('/[\r\n]+/', $input);
        if ($lines === false) { throw new RuntimeException('Cannot split test barrier input'); }
        $state->secrets = array_values(array_unique([...$state->secrets, ...array_filter($lines, static fn (string $line): bool => $line !== '')]));
        try {
            if (!is_resource($state->input)) { throw new RuntimeException('Test barrier input already closed'); }
            $offset = 0;
            set_error_handler(static function (): never { throw new RuntimeException('Test barrier input failed'); });
            try {
                while ($offset < strlen($input)) {
                    $written = fwrite($state->input, substr($input, $offset));
                    if ($written === false || $written === 0) { throw new RuntimeException('Cannot write test barrier input'); }
                    $offset += $written;
                }
                if (!fflush($state->input)) { throw new RuntimeException('Cannot flush test barrier input'); }
            } finally { restore_error_handler(); }
            $state->event('barrier-input-flushed');
        } catch (Throwable $error) { SubprocessFailure::raise($state, 'barrier-input', $error); }
    }

    public static function input(SubprocessState $state, #[\SensitiveParameter] string $input): void
    {
        $lines = preg_split('/[\r\n]+/', $input);
        if ($lines === false) { throw new RuntimeException('Cannot split test command input'); }
        $state->secrets = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
        try {
            if (!is_resource($state->input)) { throw new RuntimeException('Test command input already closed'); }
            $offset = 0;
            while ($offset < strlen($input)) {
                set_error_handler(static function (): never { throw new RuntimeException('Test command input failed'); });
                try { $written = fwrite($state->input, substr($input, $offset)); }
                finally { restore_error_handler(); }
                if ($written === false || $written === 0) { throw new RuntimeException('Cannot write test command input'); }
                $offset += $written;
            }
            fclose($state->input);
            $state->input = null;
            $state->event('input-closed');
        } catch (Throwable $error) { SubprocessFailure::raise($state, 'input', $error); }
    }
}
