<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Testo\Assert;

final class RedirectAssertions
{
    public static function headers(): array { return ['Authorization: Bearer synthetic-redirect-token']; }

    public static function reject(#[\SensitiveParameter] string $url, string $expected, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): void
    {
        $old = \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            try { (new HttpClient(true))->getEndpoint($url, $headers, $maxRedirects, $deadline); }
            catch (HttpFailure $error) {
                Assert::same($error->reason, $expected);
                Assert::same($error->getPrevious(), null);
                Assert::false(TraceInspector::inspect($error, ['synthetic-redirect-token'])['leaked']);
                return;
            }
            throw new \RuntimeException('Expected bounded redirect refusal');
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $old); }
    }

    public static function expired(RedirectServer $server): void
    {
        $client = new class(true) extends HttpClient {
            public int $calls = 0;
            protected function resolve(#[\SensitiveParameter] string $host): array { ++$this->calls; return ['127.0.0.1']; }
        };
        $start = hrtime(true);
        $deadline = $start + 180_000_000;
        while (hrtime(true) <= $deadline) { usleep(1_000); }
        $old = \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            try { $client->getEndpoint($server->base . '/held/start', self::headers(), deadline: $deadline); }
            catch (HttpFailure $error) {
                Assert::same($error->reason, 'timeout');
                Assert::same($error->getPrevious(), null);
                Assert::false(TraceInspector::inspect($error, ['synthetic-redirect-token'])['leaked']);
                Assert::same($client->calls, 0, 'expired caller deadline never enters address resolution');
                Assert::same($server->rows(), [], 'expired caller deadline sends no request');
                Assert::true((hrtime(true) - $start) / 1e6 < 900, 'pre-send refusal keeps the original upper bound');
                return;
            }
            throw new \RuntimeException('Expected pre-send deadline refusal');
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $old); }
    }

}
