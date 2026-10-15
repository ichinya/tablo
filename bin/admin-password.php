<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

// Bootstrap belongs inside this boundary: .env/autoload diagnostics are private.
\Tablo\RuntimeIni::set('display_errors', value: '0');
\Tablo\RuntimeIni::set('log_errors', value: '0');
try {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit(2);
    }
    if (count($argv) !== 2 || !in_array($argv[1] ?? null, ['--help', '--show-installation', '--password-stdin'], strict: true)) {
        fwrite(STDERR, data: "Invalid invocation. Use --help.\n");
        exit(2);
    }
    set_error_handler(static function (int $severity): bool {
        if ((error_reporting() & $severity) === 0) { return false; }
        throw new RuntimeException('Local operation failed.');
    });
    require dirname(__DIR__) . '/vendor/autoload.php';
    exit(\Tablo\AdminPasswordCommand::run(array_slice($argv, offset: 1)));
} catch (\Tablo\ValidationException) {
    fwrite(STDERR, data: "Invalid password or protected input. No change committed.\n");
    exit(2);
} catch (\Tablo\InstallationException $error) {
    fwrite(STDERR, data: "Existing installation unavailable or incompatible. No change committed.\n");
    if ($error->getCode() === \Tablo\InstallationException::UNSUPPORTED_CURL) {
        fwrite(STDERR, \Tablo\InstallationException::CURL_DIAGNOSTIC . "\n");
    }
    exit(3);
} catch (\Tablo\PasswordConflict) {
    fwrite(STDERR, data: "Credential changed. Select the installation again; no automatic retry.\n");
    exit(4);
} catch (PDOException $error) {
    // This command uses SQLite; its native driver code is integer or absent/NULL.
    /** @var array{1?: ?int, ...}|null $information */
    $information = $error->errorInfo;
    if (in_array(($information[1] ?? 0) & 255, [5, 6], strict: true)) {
        fwrite(STDERR, data: "Installation busy. No automatic retry.\n");
        exit(4);
    }
    fwrite(STDERR, data: "Storage failure. Verify access before another attempt; outcome may be uncertain.\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, data: "Local operation failed. Verify access before another attempt; outcome may be uncertain.\n");
    exit(1);
}
