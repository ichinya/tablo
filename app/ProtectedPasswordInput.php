<?php
declare(strict_types=1);

namespace Tablo;

final class ProtectedPasswordInput
{
    public static function readPasswords(): array
    {
        if (stream_isatty(STDIN)) { throw ValidationException::field(field: 'input', message: 'Protected stdin required.'); }
        // 2 * (72 bytes + CRLF) = 148; one extra byte detects overlong input.
        $input = stream_get_contents(STDIN, length: 149);
        if (!is_string($input) || strlen($input) > 148 || !feof(STDIN)
            || str_contains($input, "\0") || str_contains($input, "\xEF\xBB\xBF")
            || preg_match('/\A([^\r\n]{0,72})(?:\r\n|\n)([^\r\n]{0,72})(?:\r\n|\n)\z/D', $input, $lines) !== 1
            || preg_match('//u', $input) !== 1) {
            throw ValidationException::field(field: 'input', message: 'Invalid protected input.');
        }
        return [$lines[1], $lines[2]];
    }
}
