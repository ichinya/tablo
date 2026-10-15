<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

\Tablo\RuntimeIni::set('display_errors', value: '0');
\Tablo\RuntimeIni::set('log_errors', value: '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Key preflight failed.');
});
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1) { exit(64); }
    require dirname(__DIR__) . '/vendor/autoload.php';
    Tablo\Database::preflightExternalKey();
    fwrite(STDOUT, data: "External key preflight passed. Historical identity requires retained authenticated ciphertext or an operator-verified exact-byte transfer.\n");
} catch (Throwable $error) {
    fwrite(STDERR, data: "External key preflight failed. Check the original key and existing database.\n");
    if ($error instanceof Tablo\InstallationException && $error->getCode() === Tablo\InstallationException::UNSUPPORTED_CURL) {
        fwrite(STDERR, Tablo\InstallationException::CURL_DIAGNOSTIC . "\n");
    }
    if ($error instanceof Tablo\SharedKeyFailure && $error->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        fwrite(STDERR, Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
    }
    exit(1);
}
