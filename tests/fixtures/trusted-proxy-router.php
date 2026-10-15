<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureValue.php';

// Test-only proxy. Public ingress overwrites; controlled internal ingress appends.
// No REMOTE_ADDR override, special application endpoint or header backdoor.
$peer = $_SERVER['REMOTE_ADDR'];
$chain = $peer;
if (getenv('TABLO_FIXTURE_INTERNAL') === '1') {
    $incoming = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $chain = $incoming === '' ? $peer : $incoming . ',' . $peer;
}
$headers = ['X-Forwarded-For: ' . $chain];
foreach (['CONTENT_TYPE' => 'Content-Type', 'HTTP_COOKIE' => 'Cookie', 'HTTP_FORWARDED' => 'Forwarded',
    'HTTP_X_REAL_IP' => 'X-Real-IP', 'HTTP_CLIENT_IP' => 'Client-IP', 'HTTP_X_FORWARDED_PROTO' => 'X-Forwarded-Proto'] as $key => $name) {
    if (!(array_key_exists($key, $_SERVER))) { continue; }
 $headers[] = $name . ': ' . \Tablo\Tests\Support\FixtureValue::string($_SERVER[$key]);
}
file_put_contents(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_FIXTURE_OBSERVATIONS')), json_encode([
    'peer' => $peer, 'outbound_source' => getenv('TABLO_FIXTURE_SOURCE'), 'xff' => $chain,
    'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$curl = curl_init(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_FIXTURE_BACKEND')) . $_SERVER['REQUEST_URI']);
curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_INTERFACE => getenv('TABLO_FIXTURE_SOURCE'),
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'], CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5]);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { curl_setopt($curl, CURLOPT_POSTFIELDS, file_get_contents('php://input')); }
$raw = curl_exec($curl);
if ($raw === false) { http_response_code(502); echo 'Fixture proxy transport failed.'; return; }
$raw = \Tablo\Tests\Support\FixtureValue::string($raw);
$size = \Tablo\Tests\Support\FixtureValue::integer(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
http_response_code(\Tablo\Tests\Support\FixtureValue::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE)));
foreach (explode("\r\n", substr($raw, offset: 0, length: $size)) as $line) {
    if (!str_contains($line, ':')) { continue; }
    $name = strtolower(explode(':', $line, limit: 2)[0]);
    if (!in_array($name, ['connection', 'transfer-encoding', 'content-length', 'date'], strict: true)) {
        header($line, replace: false);
    }
}
echo substr($raw, \Tablo\Tests\Support\FixtureValue::integer($size));
