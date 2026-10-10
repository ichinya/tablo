<?php
declare(strict_types=1);

// Bootstrap belongs inside this boundary: .env/autoload diagnostics are private.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit(2);
    }
    if (count($argv) !== 2 || !in_array($argv[1], ['--help', '--show-installation', '--password-stdin'], true)) {
        fwrite(STDERR, "Invalid invocation. Use --help.\n");
        exit(2);
    }
    set_error_handler(static function (int $severity): bool {
        if ((error_reporting() & $severity) === 0) { return false; }
        throw new RuntimeException('Local operation failed.');
    });
    require dirname(__DIR__) . '/vendor/autoload.php';
    exit(\Tablo\AdminPasswordCommand::run(array_slice($argv, 1)));
} catch (\Tablo\ValidationException) {
    fwrite(STDERR, "Invalid password or protected input. No change committed.\n");
    exit(2);
} catch (\Tablo\InstallationException) {
    fwrite(STDERR, "Existing installation unavailable or incompatible. No change committed.\n");
    exit(3);
} catch (\Tablo\PasswordConflict) {
    fwrite(STDERR, "Credential changed. Select the installation again; no automatic retry.\n");
    exit(4);
} catch (PDOException $error) {
    if (in_array(($error->errorInfo[1] ?? 0) & 255, [5, 6], true)) {
        fwrite(STDERR, "Installation busy. No automatic retry.\n");
        exit(4);
    }
    fwrite(STDERR, "Storage failure. Verify access before another attempt; outcome may be uncertain.\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Local operation failed. Verify access before another attempt; outcome may be uncertain.\n");
    exit(1);
}
