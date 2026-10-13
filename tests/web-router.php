<?php
declare(strict_types=1);
require_once __DIR__ . '/Support/FixtureRequest.php';

$path = \Tablo\Tests\Support\FixtureRequest::path($_SERVER['REQUEST_URI']);
if ($path !== '/' && is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}
require dirname(__DIR__) . '/vendor/autoload.php';
// Windows drops empty proc_open environment entries. Fixture policy is explicit,
// including empty, and cannot inherit installation forwarding trust.
$fixtureProxies = getenv('TABLO_TEST_TRUSTED_PROXIES');
putenv('TABLO_TRUSTED_PROXIES=' . ($fixtureProxies ? $fixtureProxies : ''));
require __DIR__ . '/FixtureGitHubHttp.php';
$runtime = getenv('TABLO_TEST_RUNTIME');
if (!$runtime) { throw new RuntimeException('HTTP fixture requires an isolated runtime directory'); }
$clock = null;
if (getenv('TABLO_TEST_SESSION_CLOCK') === '1') {
    $clock = static function() use ($runtime): int {
        $raw = \Tablo\RequiredFile::bytes(file_get_contents(dirname($runtime) . '/runtime-clock'));
        if (preg_match('/^[0-9]+$/D', $raw) !== 1 || strlen($raw) > 10) { throw new RuntimeException('Invalid owned session clock.'); }
        return (int) $raw;
    };
}
(new Tablo\Web(new FixtureGitHubHttp(), $runtime, $clock))->run();
