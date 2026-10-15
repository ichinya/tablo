<?php
declare(strict_types=1);

// Even bootstrap, filesystem warnings and DB diagnostics stay inside the fixed boundary.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Worker operation failed.');
});
try {
    if ($argc > 2 || ($argc === 2 && $argv[1] !== '--stop')) {
        fwrite(STDERR, "Usage: php bin/worker.php [--stop]\n");
        exit(64);
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    $db = \Tablo\Database::connect();
    if ($argc === 2) {
        $state = new \Tablo\WorkerStateRepository($db);
        $generation = $state->generation();
        $requested = $generation !== null && $state->requestStop($generation);
        fwrite(STDOUT, $requested ? "Worker stop requested.\n" : "No current worker generation.\n");
        exit(0);
    }
    $worker = new \Tablo\PeriodicWorker($db, new \Tablo\HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'));
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($worker): void { $worker->stop(); });
        pcntl_signal(SIGTERM, static function () use ($worker): void { $worker->stop(); });
    }
    // Windows portable --stop is supported; no unexercised console-event promise.
    exit($worker->run());
} catch (Throwable) {
    fwrite(STDERR, "Worker failed. Check installation configuration and local storage.\n");
    exit(1);
}
