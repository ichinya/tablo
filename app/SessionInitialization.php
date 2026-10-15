<?php
declare(strict_types=1);

namespace Tablo;

final class SessionInitialization
{
    public static function start(string $runtimeDirectory): void
    {
        foreach (['sessions', 'views'] as $dir) {
            if (!(!is_dir($runtimeDirectory . '/' . $dir))) { continue; }

                mkdir($runtimeDirectory . '/' . $dir, permissions: 0o700, recursive: true);

        }
        session_save_path($runtimeDirectory . '/sessions');
        \Tablo\RuntimeIni::set('session.use_strict_mode', value: '1');
        \Tablo\RuntimeIni::set('session.use_only_cookies', value: '1');
        session_name('tablo_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
            'secure' => getenv('TABLO_COOKIE_SECURE') === '1' || (!!($_SERVER['HTTPS'] ?? null) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        SessionExpiry::expire();
    }
}
