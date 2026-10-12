<?php
declare(strict_types=1);

namespace Tablo;

// Serving/code-loading liveness only. Never construct application services here.
final class ContainerHealth
{
    public static function available(): bool
    {
        if (PHP_VERSION_ID < 80_200 || !function_exists('session_start')) { return false; }
        foreach (['ctype', 'curl', 'fileinfo', 'iconv', 'json', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_sqlite', 'xml'] as $extension) {
            if (!extension_loaded($extension)) { return false; }
        }
        foreach ([Web::class, \Phalcon\Di\FactoryDefault::class, \Phalcon\Mvc\Micro::class,
            \Phalcon\Http\Response::class, \Phalcon\Mvc\View\Simple::class, \Phalcon\Mvc\View\Engine\Volt::class,
            Database::class, Auth::class, SiteRepository::class, TokenVault::class] as $class) {
            if (!class_exists($class)) { return false; }
        }
        return true;
    }
}
