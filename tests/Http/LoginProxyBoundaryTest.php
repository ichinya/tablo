<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\ClientAddress;
use Tablo\Tests\Support\TrustedProxy;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class LoginProxyBoundaryTest extends LoginProxyFixtureCase
{


    #[Test]
    public function twoRealProxyHopsAndAnUntrustedMiddleSelectTheObservedBoundary(): void
    {
        foreach (['127.0.0.10,127.0.0.11' => '127.0.0.2', '127.0.0.11' => '127.0.0.10'] as $trust => $expected) {
            $web = new WebFixture(trustedProxies: $trust);
            $edge = null; $internal = null;
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
                Assert::same($this->attempt($web, '127.0.0.10', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), ['X-Forwarded-For: ' . $chain])['status'], 422);
                Assert::same($this->rows($web), $expected);
            }
            Assert::same($this->attempt($web, '127.0.0.10', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), [
                'X-Forwarded-For: 203.0.113.99', 'X-Forwarded-For: 2001:DB8::42'])['status'], 422);
            Assert::same($this->rows($web), $expected);
            foreach ([[], ['X-Real-IP: 2001:db8::43', 'Forwarded: for=2001:db8::43'],
                ['X-Forwarded-For:'], ['X-Forwarded-For: bad'], ['X-Forwarded-For: 127.0.0.10'],
                ['X-Forwarded-For: 2001:db8::42,'], ['X-Forwarded-For: bad,2001:db8::42'],
                ['X-Forwarded-For: ' . str_repeat('x', times: 4097)],
                ['X-Forwarded-For: 2001:db8::42' . str_repeat(',127.0.0.10', times: 32)]] as $headers) {
                $response = $this->attempt($web, '127.0.0.10', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), $headers);
                Assert::same($response['status'], 422);
                Assert::true(str_contains($response['body'], ClientAddress::HEADER_ERROR));
                Assert::same($this->rows($web), $expected);
            }
            Assert::same($this->attempt($web, '127.0.0.10', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), ['X-Forwarded-For: 2001:db8::43'])['status'], 303);
            Assert::same($this->rows($web), $expected);
            for ($i = 0; $i < 5; ++$i) {
                $this->attempt($web, '127.0.0.10', 'incorrect', ['X-Forwarded-For: ::ffff:198.51.100.42']);
            }
            foreach (['198.51.100.42', '::FFFF:C633:642A'] as $chain) {
                Assert::same($this->attempt($web, '127.0.0.10', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), ['X-Forwarded-For: ' . $chain])['status'], 422);
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
            $response = $this->attempt($web, '127.0.0.10', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), ['X-Forwarded-For: ' . str_replace(',', replace: ', ', subject: $header)]);
            Assert::same($response['status'], 422);
            Assert::true(str_contains($response['body'], ClientAddress::HEADER_ERROR));
            Assert::same($this->rows($web), [$this->row('198.51.100.42', 2)]);
            Assert::same($this->attempt($web, '127.0.0.10', 'incorrect', [
                'X-Forwarded-For: 198.51.100.42' . str_repeat(',127.0.0.10', times: 31)])['status'], 422);
            Assert::same($this->rows($web), [$this->row('198.51.100.42', 3)]);
        } finally { $web->close(); }
    }
}
