<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

use Tablo\Tests\Support\FixtureValue;

/** @param array<string, string> $input */
function session_cookie_handle(string $url, string $jar, CurlShareHandle $share, array $input): CurlHandle
{
    $handle = curl_init($url);
    if ($handle === false) { throw new RuntimeException('Owned cookie client initialization failed.'); }
    curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    curl_setopt($handle, CURLOPT_SHARE, $share);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($input)]);
    return $handle;
}

function session_cookie_wait(CurlMultiHandle $multi, string $marker): void
{
    $deadline = microtime(true) + 5;
    $running = 0;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) { throw new RuntimeException('Owned cookie multi transport failed.'); }
        clearstatcache(true, $marker);
        if (is_file($marker)) { return; }
        if ($running === 0) { throw new RuntimeException('Owned cookie request ended before barrier.'); }
        curl_multi_select($multi, timeout: .01);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Owned cookie barrier deadline.');
}

function session_cookie_finish(CurlMultiHandle $multi, CurlHandle $selected): void
{
    $deadline = microtime(true) + 5;
    $running = 0;
    do {
        if (curl_multi_exec($multi, $running) !== CURLM_OK) { throw new RuntimeException('Owned cookie multi completion failed.'); }
        while (($message = session_cookie_message(curl_multi_info_read($multi))) !== false) {
            if ($message['handle'] !== $selected) { continue; }
            if ($message['result'] !== CURLE_OK) { throw new RuntimeException('Owned cookie response transport failed.'); }
            return;
        }
        curl_multi_select($multi, timeout: .01);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Owned cookie response deadline.');
}

/** @return array{handle: CurlHandle, result: int}|false */
function session_cookie_message(mixed $message): array|false
{
    if ($message === false) { return false; }
    if (!is_array($message) || !($message['handle'] ?? null) instanceof CurlHandle || !is_int($message['result'] ?? null)) {
        throw new RuntimeException('Owned PHP8 cookie transport message shape refused.');
    }
    return ['handle' => $message['handle'], 'result' => $message['result']];
}

$input = Tablo\JsonRecord::decode(Tablo\RequiredFile::bytes(stream_get_contents(STDIN)));
$base = FixtureValue::string(FixtureValue::at($input, 'base'));
$second = FixtureValue::string(FixtureValue::at($input, 'second'));
$jar = FixtureValue::string(FixtureValue::at($input, 'jar'));
$barrier = FixtureValue::string(FixtureValue::at($input, 'barrier'));
$clock = FixtureValue::string(FixtureValue::at($input, 'clock'));
$csrf = FixtureValue::string(FixtureValue::at($input, 'csrf'));
$share = curl_share_init(); curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
$multi = curl_multi_init();
$manual = session_cookie_handle($base . '/sites/1/check', $jar, $share, ['_csrf' => $csrf]);
curl_multi_add_handle($multi, $manual);
session_cookie_wait($multi, $barrier . '/entered');
file_put_contents($clock, (string) (Tablo\Tests\Support\SessionFixture::START + 1), LOCK_EX);
$login = session_cookie_handle($second . '/login', $jar, $share, ['_csrf' => $csrf,
    'password' => Tablo\Tests\Support\FixtureCredentials::syntheticPassword()]);
curl_multi_add_handle($multi, $login);
session_cookie_finish($multi, $login);
$loginStatus = (int) curl_getinfo($login, CURLINFO_RESPONSE_CODE);
if ($loginStatus !== 303) { throw new RuntimeException('Concurrent cookie-client login failed.'); }
curl_setopt($login, CURLOPT_COOKIELIST, value: 'FLUSH');
file_put_contents($barrier . '/new-cookie-ready', data: 'ready');
session_cookie_wait($multi, $barrier . '/release');
session_cookie_finish($multi, $manual);
curl_setopt($manual, CURLOPT_COOKIELIST, value: 'FLUSH');
$raw = curl_multi_getcontent($manual);
if (!is_string($raw)) { throw new RuntimeException('Owned manual response missing.'); }
$headers = substr($raw, offset: 0, length: (int) curl_getinfo($manual, CURLINFO_HEADER_SIZE));
file_put_contents($barrier . '/manual-response.headers', $headers);
echo json_encode(['loginStatus' => $loginStatus, 'manualStatus' => (int) curl_getinfo($manual, CURLINFO_RESPONSE_CODE),
    'staleSessionCookieIssued' => stripos($headers, needle: 'Set-Cookie: tablo_session=') !== false,
    'cachePolicyPreserved' => preg_match('/^Cache-Control: no-store\s*$/mi', $headers) === 1], JSON_THROW_ON_ERROR);
curl_multi_remove_handle($multi, $login); curl_multi_remove_handle($multi, $manual);
unset($login, $manual, $multi, $share);
