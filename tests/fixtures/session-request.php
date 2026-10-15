<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

$url = $argv[1] ?? null; $jar = $argv[2] ?? null;
if (!is_string($url) || !is_string($jar)) { throw new RuntimeException('Owned session request requires URL and jar.'); }
$input = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(stream_get_contents(STDIN)));
$curl = curl_init($url);
if ($curl === false) { throw new RuntimeException('Cannot initialize owned session request.'); }
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROXY => '',
    CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($input)]);
$raw = curl_exec($curl);
if (!is_string($raw)) { throw new RuntimeException('Owned session request failed.'); }
$size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
$headers = substr($raw, offset: 0, length: $size);
echo json_encode(['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
    'sessionCookieIssued' => stripos($headers, needle: 'Set-Cookie: tablo_session=') !== false,
    'cachePolicyPreserved' => preg_match('/^Cache-Control: no-store\s*$/mi', $headers) === 1,
    'loginRedirect' => str_contains($headers, 'Location: /login'), 'dashboardRedirect' => str_contains($headers, "Location: /\r\n")], JSON_THROW_ON_ERROR);
