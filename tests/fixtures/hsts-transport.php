<?php
declare(strict_types=1);

// Plain HTTP edge fixture: overwrite untrusted XFP with the accepted connection's
// scheme. TLS/CGI qualification uses a separately owned genuine TLS frontend.
$backend = getenv('TABLO_HSTS_BACKEND', local_only: true);
if (!is_string($backend) || preg_match('~^http://127\.0\.0\.1:[0-9]{1,5}$~D', $backend) !== 1) {
    throw new RuntimeException('Invalid owned HSTS backend.');
}
$target = $_SERVER['REQUEST_URI'] ?? '/';
if (strlen($target) > 1024 || !str_starts_with($target, '/')) {
    throw new RuntimeException('Invalid owned HSTS target.');
}
$curl = curl_init($backend . $target);
if ($curl === false) { throw new RuntimeException('Cannot open owned HSTS upstream.'); }
try {
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROXY => '',
        CURLOPT_HTTPHEADER => ['X-Forwarded-Proto: http']]);
    $raw = curl_exec($curl);
    if (!is_string($raw)) { throw new RuntimeException('Owned HSTS upstream failed.'); }
    $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    http_response_code((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
    foreach (explode("\r\n", substr($raw, offset: 0, length: $size)) as $line) {
        if (!str_contains($line, ':') || str_starts_with(strtolower($line), 'connection:')) { continue; }
        header($line, replace: false);
    }
    $log = getenv('TABLO_HSTS_EDGE_LOG', local_only: true);
    if ($log) {
        file_put_contents($log, json_encode(['received' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
            'forwarded' => 'http', 'transport' => 'plain-http'], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }
    echo substr($raw, $size);
} finally { unset($curl); }
