<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\ClientAddress;
use Tablo\Tests\Support\TrustedProxy;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class LoginProxyTest
{
    private const PASSWORD = 'fixture-password';

    #[Test]
    public function directAndUntrustedPeersIgnoreSpoofedAndDuplicateWireHeaders(): void
    {
        foreach (['', '127.0.0.10'] as $trust) {
            $web = new WebFixture(trustedProxies: $trust);
            try {
                $web->authenticate();
                for ($i = 0; $i < 5; ++$i) {
                    $this->attempt($web, '127.0.0.2', 'incorrect', ['X-Forwarded-For: 198.51.100.' . $i]);
                }
                foreach ([['X-Forwarded-For: 198.51.100.99'], ['X-Forwarded-For: bad'],
                    ['X-Forwarded-For: ' . str_repeat('x', 4097)],
                    ['X-Forwarded-For: 198.51.100.1', 'X-Forwarded-For: 198.51.100.2'],
                    ['Forwarded: for=198.51.100.1', 'X-Real-IP: 198.51.100.1', 'Client-IP: 198.51.100.1']] as $headers) {
                    $response = $this->attempt($web, '127.0.0.2', self::PASSWORD, $headers);
                    Assert::same($response['status'], 422);
                    Assert::true(str_contains($response['body'], '15 минут'));
                }
                Assert::same($this->rows($web), [$this->row('127.0.0.2', 5)]);
                Assert::same($this->attempt($web, '127.0.0.3', self::PASSWORD)['status'], 303);
                Assert::same($this->rows($web), [$this->row('127.0.0.2', 5)]);
            } finally { $web->close(); }
        }
    }

    #[Test]
    public function aSanitizingEdgeSeparatesSocketClientsAndCannotResetExhaustedA(): void
    {
        $web = new WebFixture(trustedProxies: '127.0.0.10');
        $edge = null;
        try {
            $web->authenticate();
            $edge = new TrustedProxy($web->server->base, '127.0.0.10');
            for ($i = 0; $i < 5; ++$i) {
                Assert::same($this->attempt($web, '127.0.0.2', 'incorrect', ['X-Forwarded-For: 203.0.113.' . $i], $edge->server->base)['status'], 422);
            }
            // B succeeds with its own fresh jar; A is durable after reopening the DB.
            Assert::same($this->attempt($web, '127.0.0.3', self::PASSWORD, base: $edge->server->base)['status'], 303);
            foreach ([[], ['X-Forwarded-For: bad'], ['X-Forwarded-For: 203.0.113.1', 'X-Forwarded-For: 203.0.113.2'],
                ['X-Forwarded-For: 2001:db8::42', 'Forwarded: for=203.0.113.5', 'X-Real-IP: 203.0.113.6',
                    'Client-IP: 203.0.113.7', 'X-Forwarded-Proto: https']] as $headers) {
                Assert::same($this->attempt($web, '127.0.0.2', self::PASSWORD, $headers, $edge->server->base)['status'], 422);
                Assert::same($this->rows($web), [$this->row('127.0.0.2', 5)]);
            }
            foreach ($edge->observations() as $observation) {
                Assert::same($observation['xff'], $observation['peer']);
                Assert::same($observation['outbound_source'], '127.0.0.10');
                Assert::true(in_array($observation['peer'], ['127.0.0.2', '127.0.0.3'], true));
            }
        } finally { $edge?->close(); $web->close(); }
    }

    #[Test]
    public function twoRealProxyHopsAndAnUntrustedMiddleSelectTheObservedBoundary(): void
    {
        foreach (['127.0.0.10,127.0.0.11' => '127.0.0.2', '127.0.0.11' => '127.0.0.10'] as $trust => $expected) {
            $web = new WebFixture(trustedProxies: $trust);
            $internal = $edge = null;
            try {
                $web->authenticate();
                $internal = new TrustedProxy($web->server->base, '127.0.0.11', internal: true);
                $edge = new TrustedProxy($internal->server->base, '127.0.0.10');
                Assert::same($this->attempt($web, '127.0.0.2', 'incorrect', ['X-Forwarded-For: 203.0.113.99'], $edge->server->base)['status'], 422);
                Assert::same($this->rows($web), [$this->row($expected, 1)]);
                foreach ($internal->observations() as $observation) {
                    Assert::same($observation['peer'], '127.0.0.10');
                    Assert::same($observation['xff'], '127.0.0.2,127.0.0.10');
                }
            } finally { $edge?->close(); $internal?->close(); $web->close(); }
        }
    }

    #[Test]
    public function trustedIngressRefusesFallbacksAndCanonicalIdentityBypasses(): void
    {
        $web = new WebFixture(trustedProxies: '127.0.0.10');
        try {
            $web->authenticate();
            // A real trusted socket sends synthetic IPv6 history, exercising PHP ingress.
            for ($i = 0; $i < 5; ++$i) {
                $this->attempt($web, '127.0.0.10', 'incorrect', ['X-Forwarded-For: 2001:0DB8:0:0:0:0:0:0042']);
            }
            $expected = [$this->row('2001:db8::42', 5)];
            foreach (['2001:db8::42', '203.0.113.1,2001:db8::42', '203.0.113.2,2001:db8::42,127.0.0.10',
                '2001:db8::42,127.0.0.10,127.0.0.10'] as $chain) {
                Assert::same($this->attempt($web, '127.0.0.10', self::PASSWORD, ['X-Forwarded-For: ' . $chain])['status'], 422);
                Assert::same($this->rows($web), $expected);
            }
            Assert::same($this->attempt($web, '127.0.0.10', self::PASSWORD, [
                'X-Forwarded-For: 203.0.113.99', 'X-Forwarded-For: 2001:DB8::42'])['status'], 422);
            Assert::same($this->rows($web), $expected);
            foreach ([[], ['X-Real-IP: 2001:db8::43', 'Forwarded: for=2001:db8::43'],
                ['X-Forwarded-For:'], ['X-Forwarded-For: bad'], ['X-Forwarded-For: 127.0.0.10'],
                ['X-Forwarded-For: 2001:db8::42,'], ['X-Forwarded-For: bad,2001:db8::42'],
                ['X-Forwarded-For: ' . str_repeat('x', 4097)],
                ['X-Forwarded-For: 2001:db8::42' . str_repeat(',127.0.0.10', 32)]] as $headers) {
                $response = $this->attempt($web, '127.0.0.10', self::PASSWORD, $headers);
                Assert::same($response['status'], 422);
                Assert::true(str_contains($response['body'], ClientAddress::HEADER_ERROR));
                Assert::same($this->rows($web), $expected);
            }
            Assert::same($this->attempt($web, '127.0.0.10', self::PASSWORD, ['X-Forwarded-For: 2001:db8::43'])['status'], 303);
            Assert::same($this->rows($web), $expected);
            for ($i = 0; $i < 5; ++$i) {
                $this->attempt($web, '127.0.0.10', 'incorrect', ['X-Forwarded-For: ::ffff:198.51.100.42']);
            }
            foreach (['198.51.100.42', '::FFFF:C633:642A'] as $chain) {
                Assert::same($this->attempt($web, '127.0.0.10', self::PASSWORD, ['X-Forwarded-For: ' . $chain])['status'], 422);
            }
            $expected[] = $this->row('198.51.100.42', 5);
            usort($expected, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
            Assert::same($this->rows($web), $expected);
        } finally { $web->close(); }
    }

    #[Test]
    public function actualDuplicateWireFieldsAndExactLimitsHaveDeterministicKeys(): void
    {
        $web = new WebFixture(trustedProxies: '127.0.0.10');
        try {
            $web->authenticate();
            // SAPI merges these wire fields into a chain; the rightmost untrusted hop wins.
            Assert::same($this->attempt($web, '127.0.0.10', 'incorrect', [
                'X-Forwarded-For: 203.0.113.99', 'X-Forwarded-For: 198.51.100.42'])['status'], 422);
            Assert::same($this->rows($web), [$this->row('198.51.100.42', 1)]);
            // Interior whitespace survives SAPI outer trimming, so these are exact parser byte boundaries.
            $header = '198.51.100.42,' . str_repeat(' ', 4096 - strlen('198.51.100.42,127.0.0.10')) . '127.0.0.10';
            Assert::same(strlen($header), 4096);
            Assert::same($this->attempt($web, '127.0.0.10', 'incorrect', ['X-Forwarded-For: ' . $header])['status'], 422);
            Assert::same($this->rows($web), [$this->row('198.51.100.42', 2)]);
            $response = $this->attempt($web, '127.0.0.10', self::PASSWORD, ['X-Forwarded-For: ' . str_replace(',', ', ', $header)]);
            Assert::same($response['status'], 422);
            Assert::true(str_contains($response['body'], ClientAddress::HEADER_ERROR));
            Assert::same($this->rows($web), [$this->row('198.51.100.42', 2)]);
            Assert::same($this->attempt($web, '127.0.0.10', 'incorrect', [
                'X-Forwarded-For: 198.51.100.42' . str_repeat(',127.0.0.10', 31)])['status'], 422);
            Assert::same($this->rows($web), [$this->row('198.51.100.42', 3)]);
        } finally { $web->close(); }
    }

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

    private function attempt(WebFixture $web, string $source, #[\SensitiveParameter] string $password,
        array $headers = [], ?string $base = null): array
    {
        $jar = 'jar-' . bin2hex(random_bytes(4));
        $login = $web->request('/login', headers: $headers, source: $source, base: $base, jar: $jar);
        Assert::same($login['status'], 200);
        $response = $web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => $password],
            headers: $headers, source: $source, base: $base, jar: $jar);
        Assert::true(in_array($response['status'], [422, 303], true));
        $access = $web->request('/', source: $source, base: $base, jar: $jar);
        Assert::same($access['status'], $response['status'] === 303 ? 200 : 303);
        if ($response['status'] !== 303) {
            Assert::true(str_contains($access['headers'], 'Location: /login'));
        }
        return $response;
    }

    #[Test]
    public function quotedIniPathsPreserveConfigDiagnosticsWithWindowsShortPathCharacters(): void
    {
        $directory = new TemporaryDirectory('tablo~proxy-errors-');
        $server = null;
        try {
            $root = dirname(__DIR__, 2);
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

    private function rows(WebFixture $web): array
    {
        $web->reopenDatabase();
        return $web->database()->query('SELECT key, failures FROM login_limits ORDER BY key')->fetchAll();
    }

    private function row(string $address, int $failures): array
    {
        return ['key' => hash('sha256', $address), 'failures' => $failures];
    }
}
