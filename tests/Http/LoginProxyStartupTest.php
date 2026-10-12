<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\ClientAddress;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class LoginProxyStartupTest extends LoginProxyFixtureCase
{


    #[Test]
    public function invalidConfigHasFixedDiagnosticsBeforeRuntimeDatabaseOrSessionCreation(): void
    {
        $web = new WebFixture(trustedProxies: 'fixture-sensitive-invalid-value');
        try {
            $response = $web->request('/login');
            Assert::same($response['status'], 500);
            Assert::true(!str_contains($response['headers'], 'tablo_session'));
            Assert::true(!is_dir($web->directory->path . '/runtime'));
            Assert::true(!is_file($web->directory->path . '/test.sqlite'));
            $log = $web->server->diagnostics();
            Assert::true(str_contains($log, ClientAddress::CONFIG_ERROR), $log);
            Assert::true(!str_contains($log . $response['body'], 'fixture-sensitive-invalid-value'));
        } finally { $web->close(); }
    }



    #[Test]
    public function quotedIniPathsPreserveConfigDiagnosticsWithWindowsShortPathCharacters(): void
    {
        $directory = new TemporaryDirectory('tablo~proxy-errors-');
        $server = null;
        try {
            $root = dirname(__DIR__, levels: 2);
            $server = new TestServer($directory, $root . '/tests/web-router.php', $root . '/public', [
                'TABLO_DB' => $directory->path . '/unused.sqlite', 'TABLO_TEST_RUNTIME' => $directory->path . '/runtime',
                'TABLO_TEST_TRUSTED_PROXIES' => 'fixture-sensitive-invalid-value',
            ]);
            $curl = curl_init($server->base . '/login');
            curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $body = curl_exec($curl);
            Assert::true(is_string($body));
            Assert::same(curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 500);
            $log = $server->diagnostics();
            Assert::true(str_contains($log, ClientAddress::CONFIG_ERROR), $log);
            Assert::true(!str_contains($log . $body, 'fixture-sensitive-invalid-value'));
            Assert::true(!is_dir($directory->path . '/runtime'));
            Assert::true(!is_file($directory->path . '/unused.sqlite'));
        } finally { $server?->close(); $directory->close(); }
    }
}
