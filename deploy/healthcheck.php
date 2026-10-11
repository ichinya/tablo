<?php
declare(strict_types=1);

// Standalone image probe: no application autoload, environment target or diagnostics.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('Healthcheck failed.'); });
$healthy = false;
try {
    $body = '';
    $headerBytes = 0;
    $curl = curl_init('http://127.0.0.1:80/_tablo/health');
    if ($curl !== false && curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_PROXY => '',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 16) { return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headerBytes): int {
            $headerBytes += strlen($line);
            return $headerBytes <= 4096 ? strlen($line) : 0;
        },
    ])) {
        $healthy = curl_exec($curl) === true && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && $body === "ok\n";
    }
} catch (Throwable) {
    // A fixed message is safe to retain in Docker's Health.Log.
}
echo $healthy ? "healthy\n" : "unavailable\n";
exit($healthy ? 0 : 1);
