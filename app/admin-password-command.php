<?php
declare(strict_types=1);

namespace Tablo;

use SensitiveParameter;

final class AdminPasswordCommand
{
    public static function run(#[SensitiveParameter] array $arguments): int
    {
        if (PHP_SAPI !== 'cli' || count($arguments) !== 1
            || !in_array($arguments[0], ['--help', '--show-installation', '--password-stdin'], true)) {
            fwrite(STDERR, "Invalid invocation. Use --help.\n");
            return 2;
        }
        if ($arguments[0] === '--help') {
            fwrite(STDOUT, "Usage: php bin/admin-password.php --show-installation|--password-stdin|--help\n");
            return 0;
        }
        $db = Database::openExisting();
        $identity = Database::installationIdentity($db);
        $auth = new Auth($db);
        $snapshot = $auth->credentialFingerprint();
        if ($snapshot === null) { throw new InstallationException('Administrator unavailable.'); }
        // JSON string escaping is used only for the public filename, never for secrets.
        $file = json_encode($identity['file'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Escape Unicode format controls too, so filenames cannot alter terminal display.
        $file = preg_replace_callback('/[\p{Cc}\p{Cf}]/u', static fn (array $match): string =>
            '\\x' . bin2hex($match[0]), $file);
        if (fwrite(STDOUT, 'Installation: ' . $file . '; administrator=1; schema=' . $identity['schema'] . "\n") === false) {
            throw new \RuntimeException('Output unavailable.');
        }
        fflush(STDOUT);
        if ($arguments[0] === '--show-installation') { return 0; }
        [$password, $confirmation] = self::readPasswords();
        $auth->changePassword($password, $confirmation, $snapshot);
        // Output failure after COMMIT cannot undo the committed credential change.
        if (fwrite(STDOUT, "Administrator password changed. Previous sessions require login.\n") === false) {
            throw new \RuntimeException('Output unavailable.');
        }
        return 0;
    }

    private static function readPasswords(): array
    {
        if (stream_isatty(STDIN)) { throw new ValidationException(['input' => 'Protected stdin required.']); }
        // 2 * (72 bytes + CRLF) = 148; one extra byte detects overlong input.
        $input = stream_get_contents(STDIN, 149);
        if (!is_string($input) || strlen($input) > 148 || !feof(STDIN)
            || str_contains($input, "\0") || str_contains($input, "\xEF\xBB\xBF")
            || preg_match('/\A([^\r\n]{0,72})(?:\r\n|\n)([^\r\n]{0,72})(?:\r\n|\n)\z/D', $input, $lines) !== 1
            || preg_match('//u', $input) !== 1) {
            throw new ValidationException(['input' => 'Invalid protected input.']);
        }
        return [$lines[1], $lines[2]];
    }
}
