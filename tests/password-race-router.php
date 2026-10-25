<?php
declare(strict_types=1);

namespace Tablo {
    function time(): int
    {
        $barrier = getenv('TABLO_TEST_AUTH_BARRIER');
        /** @var list<array{class?: class-string, function?: string, ...}> $trace */
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2);
        $caller = $trace[1] ?? [];
        if ($barrier && is_file($barrier . '/before-begin-armed')
            && ($caller['class'] ?? null) === Auth::class && ($caller['function'] ?? null) === 'loginSession') {
            file_put_contents($barrier . '/before-login-begin', data: 'Auth.loginSession before BEGIN IMMEDIATE');
            register_shutdown_function(static function () use ($barrier): void {
                file_put_contents($barrier . '/login-status', (string) http_response_code());
            });
            $deadline = microtime(true) + 8;
            while (!is_file($barrier . '/release-login-begin') && microtime(true) < $deadline) {
                clearstatcache(); usleep(20_000);
            }
            if (!is_file($barrier . '/release-login-begin')) { throw new \RuntimeException('Before-BEGIN barrier timed out'); }
        }
        return \time();
    }
    // Test-only interception at the actual Web authentication boundary: Auth has
    // returned its committed verified snapshot, but no auth cookie is created yet.
    function session_regenerate_id(bool $deleteOldSession = false): bool
    {
        $barrier = getenv('TABLO_TEST_AUTH_BARRIER');
        if ($barrier && is_file($barrier . '/armed')) {
            file_put_contents($barrier . '/verified', data: 'ready');
            $deadline = microtime(true) + 8;
            while (!is_file($barrier . '/release') && microtime(true) < $deadline) {
                clearstatcache();
                usleep(20_000);
            }
            if (!is_file($barrier . '/release')) { throw new \RuntimeException('Authentication barrier timed out'); }
        }
        return \session_regenerate_id($deleteOldSession);
    }
}
namespace {
    if (getenv('TABLO_TEST_PROVIDER_CASE')) { require_once __DIR__ . '/fixtures/password-provider-failure.php'; }
    require __DIR__ . '/web-router.php';
}
