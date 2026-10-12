<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class ContainerHealthTest
{
    #[Test]
    public function realIndexServesOnlyExactLocalGetWithoutInitializingStorage(): void
    {
        $directory = new TemporaryDirectory('tablo-container-health-http-');
        $root = dirname(__DIR__, levels: 2);
        $server = new TestServer($directory, $root . '/public/router.php', $root . '/public', [
            'TABLO_DB' => $directory->path . '/missing/tablo.sqlite',
            'TABLO_TOKEN_KEY_FILE' => $directory->path . '/missing.key',
            // Ordinary aliases fail before Web can open storage; health ignores this setting.
            'TABLO_TRUSTED_PROXIES' => 'invalid-fixture-proxy',
        ]);
        try {
            foreach (['127.0.0.1', '127.0.0.1:80'] as $host) {
                for ($i = 0; $i < 3; ++$i) {
                    $response = ContainerHealthRequest::request($server, headers: ['Host: ' . $host, 'Cookie: tablo_session=existing-fixture']);
                    Assert::same($response['status'], 200);
                    Assert::same($response['body'], "ok\n");
                    Assert::true(str_contains(strtolower($response['headers']), 'content-type: text/plain; charset=utf-8'));
                    Assert::true(str_contains(strtolower($response['headers']), 'cache-control: no-store'));
                    Assert::true(str_contains(strtolower($response['headers']), 'x-content-type-options: nosniff'));
                    Assert::false(str_contains(strtolower($response['headers']), 'set-cookie:'));
                }
            }
            foreach (['POST', 'HEAD', 'OPTIONS'] as $method) {
                $response = ContainerHealthRequest::request($server, method: $method);
                Assert::same($response['status'], 405);
                Assert::true(str_contains($response['headers'], 'Allow: GET'));
            }
            foreach (['/_tablo/health?', '/_tablo/health?probe=1', '/_tablo/health/', '/_tablo/health/extra'] as $path) {
                Assert::same(ContainerHealthRequest::request($server, $path)['status'], 404, $path);
            }
            foreach (['localhost', '127.0.0.1:81', 'example.invalid', '127.0.0.1, 127.0.0.1'] as $host) {
                $response = ContainerHealthRequest::request($server, headers: ['Host: ' . $host, 'Forwarded: for=127.0.0.1', 'X-Forwarded-For: 127.0.0.1']);
                Assert::same($response['status'], 404);
            }
            Assert::true(ContainerHealthRequest::request($server, headers: ['Host:'])['status'] !== 200);
            foreach (['/%5ftablo/health', '/_tablo/%68ealth', '/index.php/_tablo/health', '/router.php/_tablo/health'] as $alias) {
                Assert::true(ContainerHealthRequest::request($server, $alias)['status'] !== 200, $alias);
            }
            Assert::false(is_dir($directory->path . '/missing'));
            Assert::false(file_exists($directory->path . '/missing.key'));
        } finally { $server->close(); $directory->close(); }
    }

    #[Test]
    public function failedProductionAutoloadHasFixedUnavailableResponse(): void
    {
        $directory = new TemporaryDirectory('tablo-container-health-failure-');
        $root = dirname(__DIR__, levels: 2);
        mkdir($directory->path . '/public');
        copy($root . '/public/index.php', $directory->path . '/public/index.php');
        copy($root . '/public/router.php', $directory->path . '/public/router.php');
        mkdir($directory->path . '/app');
        copy($root . '/app/RuntimeIni.php', $directory->path . '/app/RuntimeIni.php');
        $server = new TestServer($directory, $directory->path . '/public/router.php', $directory->path . '/public');
        try {
            $response = ContainerHealthRequest::request($server);
            Assert::same($response['status'], 503);
            Assert::same($response['body'], "unavailable\n");
            Assert::false(str_contains(strtolower($response['headers']), 'set-cookie:'));
            mkdir($directory->path . '/vendor');
            $stub = '<?php namespace Tablo; final class ContainerHealth { public static function available(): bool { return true; } } ';
            $headers = "header('Set-Cookie: fixture=private'); header('X-Probe-Private: fixture-private-value'); header('Content-Type: text/html'); header('Cache-Control: public'); ";
            $failures = [
                'output' => 'echo "fixture-private-value";',
                'headers' => $headers,
                'headers-throw' => $headers . 'throw new \\RuntimeException("fixture-private-value");',
                'nested-output-throw' => 'echo "fixture-private-value"; ob_start(); throw new \\RuntimeException("fixture-private-value");',
                'nested-output' => 'ob_start(); echo "fixture-private-value"; ob_start();',
                'flush-output' => 'echo "fixture-private-value"; ob_flush();',
                'closed-buffer' => 'echo "fixture-private-value"; ob_end_flush();',
                'non-removable-buffer' => 'ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE); echo "fixture-private-value";',
                'excess-buffers' => 'for ($i = 0; $i < 40; ++$i) { ob_start(); } echo "fixture-private-value";',
                'parse-error' => 'function broken( {',
            ];
            foreach (['baseline' => '', ...$failures, 'recovery' => ''] as $mode => $effect) {
                file_put_contents($directory->path . '/vendor/autoload.php', $stub . $effect);
                $response = ContainerHealthRequest::request($server);
                Assert::same($response['status'], $effect === '' ? 200 : 503, $mode);
                Assert::same($response['body'], $effect === '' ? "ok\n" : "unavailable\n", $mode);
                $responseHeaders = strtolower($response['headers']);
                Assert::true(str_contains($responseHeaders, 'content-type: text/plain; charset=utf-8'), $mode);
                Assert::true(str_contains($responseHeaders, 'cache-control: no-store'), $mode);
                Assert::true(str_contains($responseHeaders, 'x-content-type-options: nosniff'), $mode);
                Assert::false(str_contains($responseHeaders, 'set-cookie:'), $mode);
                Assert::false(str_contains($responseHeaders, 'x-probe-private:'), $mode);
                Assert::false(str_contains($response['body'], 'fixture-private-value'), $mode);
            }
        } finally { $server->close(); $directory->close(); }
    }
}
