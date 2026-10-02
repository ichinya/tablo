<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Serve only known assets. Application files and SQLite never pass through.
if (is_string($path) && preg_match('~^/assets/[a-z0-9._-]+\.(?:css|js|svg)$~D', $path) && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
