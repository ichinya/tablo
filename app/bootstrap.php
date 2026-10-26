<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidEncodingException;
use Dotenv\Exception\InvalidFileException;

// Composer's generated autoload platform check does not enforce lib-* versions.
// Refuse before environment, storage or checks can turn this into a website failure.
if (!function_exists('curl_version') || !defined('CURLOPT_REQUEST_TARGET')
    || (static function (): int {
        // Native cURL exposes its numeric version as an integer; retain the missing-key zero refusal.
        /** @var array{version_number?: int, ...} $info */
        $info = curl_version();
        return $info['version_number'] ?? 0;
    })() < 0x07_3700) {
    throw new \Tablo\InstallationException(\Tablo\InstallationException::CURL_DIAGNOSTIC,
        \Tablo\InstallationException::UNSUPPORTED_CURL);
}

// Keep getenv() compatible with existing configuration readers and preserve process overrides.
try {
    Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();
} catch (InvalidFileException | InvalidEncodingException) {
    // Parser diagnostics may include credentials from the invalid line.
    throw new RuntimeException('Не удалось загрузить .env: проверьте синтаксис и кодировку файла.');
}
