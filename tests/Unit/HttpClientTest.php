<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HttpClient;
use Tablo\HttpFailure;

use RuntimeException;
use LogicException;
use Testo\Assert;
use Testo\Test;

final class HttpClientTest
{
    #[Test]
    public function rejectsSsrfTargets(): void
    {
        foreach (['http://127.0.0.1/up', 'http://10.0.0.1/up', 'http://[::1]/up', 'http://[::ffff:127.0.0.1]/up', 'file:///etc/passwd'] as $url) {
            try {
                (new HttpClient())->get($url);
                throw new LogicException('local request accepted');
            } catch (RuntimeException $e) {
                Assert::true(!($e instanceof LogicException), 'local request accepted');
                Assert::instanceOf($e, HttpFailure::class);
                Assert::same($e->reason, str_starts_with($url, 'file:') ? 'invalid-url' : 'ssrf');
            }
        }
    }

    #[Test]
    public function classifiesDnsAndCurlFailuresWithoutSensitiveDetails(): void
    {
        $http = new class extends HttpClient {
            protected function resolve(string $host): array { return []; }
        };
        $error = null;
        try { $http->get('https://secret-host.example/private-token'); } catch (HttpFailure $caught) { $error = $caught; }
        Assert::instanceOf($error, HttpFailure::class);
        Assert::same($error->reason, 'dns');
        Assert::false(str_contains($error->getMessage(), 'secret-host') || str_contains($error->getMessage(), 'private-token'));
        foreach ([[CURLE_OPERATION_TIMEOUTED, 0, 'timeout'], [CURLE_COULDNT_RESOLVE_HOST, 0, 'dns'],
            [CURLE_COULDNT_CONNECT, 111, 'refused'], [CURLE_COULDNT_CONNECT, 61, 'refused'],
            [CURLE_COULDNT_CONNECT, 10061, 'refused'], [CURLE_COULDNT_CONNECT, 101, 'network'],
            [CURLE_SSL_CONNECT_ERROR, 0, 'tls'], [CURLE_SSL_CACERT, 0, 'tls'],
            [CURLE_SSL_CACERT_BADFILE, 0, 'tls'],
            [CURLE_GOT_NOTHING, 0, 'invalid-response'], [CURLE_FTP_WEIRD_SERVER_REPLY, 0, 'invalid-response'],
            [CURLE_RECV_ERROR, 0, 'network']] as [$curl, $os, $reason]) {
            Assert::same(HttpFailure::fromCurl($curl, $os)->reason, $reason);
        }
    }
}
