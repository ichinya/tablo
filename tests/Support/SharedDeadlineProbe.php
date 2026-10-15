<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\CurlGetTransport;
use Tablo\EndpointChain;
use Tablo\HttpDeadline;
use Testo\Assert;

/** Observe the actual chain arguments while both transfers use the native cURL transport. */
/** @phpstan-import-type Chain from EndpointChain
 * @phpstan-import-type TransportResponse from CurlGetTransport */
final class SharedDeadlineProbe
{
    public static function verify(string $base): void
    {
        // Immediate responses use a finite fixture budget; the separate exhaustion control remains 180ms.
        $deadline = \Tablo\MonotonicTime::integer(hrtime(true)) + 2_000_000_000;
        $observations = [];
        $transport = new CurlGetTransport(true, static fn (string $host): array => ['127.0.0.1'], null);
        $request = /** @param list<string> $headers
         * @param Chain|null $chain
         * @return TransportResponse */
        static function (#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers,
            #[\SensitiveParameter] ?array &$chain) use ($transport, &$observations): array {
            $before = ['deadline' => FixtureValue::row($chain)['deadline'], 'remaining' => HttpDeadline::remaining(FixtureValue::row($chain)['deadline'])];
            $response = $transport->request($url, $headers, $chain);
            $observations[] = $before + ['after' => \Tablo\Tests\Support\FixtureValue::row($chain)['deadline'], 'status' => $response['status']];
            return $response;
        };
        $result = EndpointChain::run($base . '/chain/1', RedirectAssertions::headers(), 3, $deadline, $request);
        Assert::same($result['status'], 200);
        Assert::same(count($observations), 2);
        foreach ($observations as $index => $observation) {
            Assert::same($observation['deadline'], $deadline, 'original absolute deadline reaches each native transfer');
            Assert::same($observation['after'], $deadline, 'native transport preserves the original absolute deadline');
            Assert::same($observation['status'], $index === 0 ? 302 : 200);
            Assert::true($observation['remaining'] > 0 && $observation['remaining'] <= 2_000);
        }
        Assert::true(\Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::at($observations, 1))['remaining'] <= \Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::at($observations, 0))['remaining'], 'the shared remaining budget never grows');
    }
}
