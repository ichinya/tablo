<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\TrustedProxy;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class LoginProxyTest extends LoginProxyFixtureCase
{


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
                    ['X-Forwarded-For: ' . str_repeat('x', times: 4097)],
                    ['X-Forwarded-For: 198.51.100.1', 'X-Forwarded-For: 198.51.100.2'],
                    ['Forwarded: for=198.51.100.1', 'X-Real-IP: 198.51.100.1', 'Client-IP: 198.51.100.1']] as $headers) {
                    $response = $this->attempt($web, '127.0.0.2', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), $headers);
                    Assert::same($response['status'], 422);
                    Assert::true(str_contains($response['body'], '15 минут'));
                }
                Assert::same($this->rows($web), [$this->row('127.0.0.2', 5)]);
                Assert::same($this->attempt($web, '127.0.0.3', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'))['status'], 303);
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
            Assert::same($this->attempt($web, '127.0.0.3', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), base: $edge->server->base)['status'], 303);
            foreach ([[], ['X-Forwarded-For: bad'], ['X-Forwarded-For: 203.0.113.1', 'X-Forwarded-For: 203.0.113.2'],
                ['X-Forwarded-For: 2001:db8::42', 'Forwarded: for=203.0.113.5', 'X-Real-IP: 203.0.113.6',
                    'Client-IP: 203.0.113.7', 'X-Forwarded-Proto: https']] as $headers) {
                Assert::same($this->attempt($web, '127.0.0.2', \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), $headers, $edge->server->base)['status'], 422);
                Assert::same($this->rows($web), [$this->row('127.0.0.2', 5)]);
            }
            foreach ($edge->observations() as $observation) {
                Assert::same($observation['xff'], $observation['peer']);
                Assert::same($observation['outbound_source'], '127.0.0.10');
                Assert::true(in_array($observation['peer'], ['127.0.0.2', '127.0.0.3'], strict: true));
            }
        } finally { $edge?->close(); $web->close(); }
    }
}
