<?php
declare(strict_types=1);

namespace Tablo;

final class ProtectedPasswordInput
{
    /** @return array{0: string, 1: string} */
    public static function readPasswords(): array
    {
        if (stream_isatty(STDIN)) { throw ValidationException::field(field: 'input', message: 'Protected stdin required.'); }
        // 2 * (1024 bytes + CRLF) = 2052; one extra byte detects overlong input.
        $input = stream_get_contents(STDIN, length: 2053);
        $lines = [];
        if (!is_string($input) || strlen($input) > 2052 || !feof(STDIN)
            || str_contains($input, "\0") || str_contains($input, "\xEF\xBB\xBF")
            || preg_match('/\A([^\r\n]{0,1024})(?:\r\n|\n)([^\r\n]{0,1024})(?:\r\n|\n)\z/D', $input, $lines) !== 1
            || preg_match('//u', $input) !== 1) {
            throw ValidationException::field(field: 'input', message: 'Invalid protected input.');
        }
        return [RegexCapture::text($lines, 1), RegexCapture::text($lines, 2)];
    }
}
