<?php
declare(strict_types=1);

// API requests execute the actual public entry point. Browser fixtures retain real
// Web/session/CSRF behavior with their isolated runtime and optional internal clock.
$target = $_SERVER['REQUEST_URI'] ?? '';
if (str_starts_with($target, '/api')) {
    if (getenv('TABLO_TEST_READ_API_KEY_FREE') === '1') {
        putenv('TABLO_TOKEN_KEY_FILE=' . dirname(__DIR__) . '/absent-key-canary');
        putenv('TABLO_TRUSTED_PROXIES=invalid-proxy-canary');
    }
    require dirname(__DIR__, levels: 2) . '/public/index.php';
    return;
}
$pageSource = getenv('TABLO_TEST_READ_API_PAGE_SOURCE');
if ($pageSource) {
    /** @var \Composer\Autoload\ClassLoader $loader The immutable pinned Composer entry returns its loader. */
    $loader = require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
    $loader->addClassMap(['Tablo\\ReadApiTokenPages' => $pageSource]);
}
require dirname(__DIR__) . '/web-router.php';
