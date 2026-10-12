<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class SubprocessInput
{
    public static function input(SubprocessState $state, #[\SensitiveParameter] string $input): void
    {
        $state->secrets = array_values(array_filter(preg_split('/[\r\n]+/', $input), static fn(string $line): bool => $line !== ''));
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
