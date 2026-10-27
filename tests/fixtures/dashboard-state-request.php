<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

$url = $argv[1] ?? null;
$jar = $argv[2] ?? null;
if (!is_string($url) || !is_string($jar)) { throw new RuntimeException('Owned dashboard request requires URL and cookie jar.'); }
$curl = curl_init($url);
if ($curl === false) { throw new RuntimeException('Cannot initialize owned dashboard request.'); }
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar]);
$raw = curl_exec($curl);
if (!is_string($raw)) { throw new RuntimeException('Owned dashboard request failed.'); }
$size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
$headers = substr($raw, offset: 0, length: $size);
echo json_encode(['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
    'sessionCookieIssued' => stripos($headers, needle: 'Set-Cookie: tablo_session=') !== false,
    'redirected' => stripos($headers, needle: 'Location:') !== false,
    'body' => substr($raw, $size)], JSON_THROW_ON_ERROR);
