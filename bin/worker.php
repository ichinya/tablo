<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

// Even bootstrap, filesystem warnings and DB diagnostics stay inside the fixed boundary.
\Tablo\RuntimeIni::set('display_errors', value: '0');
\Tablo\RuntimeIni::set('log_errors', value: '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Worker operation failed.');
});
try {
    // PHP supplies arbitrary CLI argument counts, not a single inferred invocation.
    /** @var non-negative-int $argc */
    /** @var list<string> $argv */
    if ($argc > 2 || ($argc === 2 && ($argv[1] ?? null) !== '--stop')) {
        fwrite(STDERR, data: "Usage: php bin/worker.php [--stop]\n");
        exit(64);
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    if ($argc === 2) {
        $db = \Tablo\Database::openWorkerControl(); // Operator control is deliberately key-independent.
        $state = new \Tablo\WorkerStateRepository($db);
        $generation = $state->generation();
        $requested = $generation !== null && $state->requestStop($generation);
        fwrite(STDOUT, $requested ? "Worker stop requested.\n" : "No current worker generation.\n");
        exit(0);
    }
    $vault = \Tablo\TokenVault::configured();
    $db = \Tablo\Database::connect(vault: $vault);
    $vault ??= \Tablo\TokenVault::forDatabase($db);
    $worker = new \Tablo\PeriodicWorker($db, new \Tablo\HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'), vault: $vault);
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($worker): void { $worker->stop(); });
        pcntl_signal(SIGTERM, static function () use ($worker): void { $worker->stop(); });
    }
    // Windows portable --stop is supported; no unexercised console-event promise.
    exit($worker->run());
} catch (Throwable $error) {
    fwrite(STDERR, data: "Worker failed. Check installation configuration and local storage.\n");
    if ($error instanceof Tablo\InstallationException && $error->getCode() === Tablo\InstallationException::UNSUPPORTED_CURL) {
        fwrite(STDERR, Tablo\InstallationException::CURL_DIAGNOSTIC . "\n");
    }
    if ($error instanceof Tablo\SharedKeyFailure && $error->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        fwrite(STDERR, Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
    }
    exit(1);
}
