<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Key preflight failed.');
});
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1) { exit(64); }
    require dirname(__DIR__) . '/vendor/autoload.php';
    Tablo\Database::preflightExternalKey();
    fwrite(STDOUT, "External key preflight passed. Historical identity requires retained authenticated ciphertext or an operator-verified exact-byte transfer.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "External key preflight failed. Check the original key and existing database.\n");
    if ($error instanceof Tablo\SharedKeyFailure && $error->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        fwrite(STDERR, Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
    }
    exit(1);
}
