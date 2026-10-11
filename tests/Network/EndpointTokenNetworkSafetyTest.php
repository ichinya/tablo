<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\RawHttpServer;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenNetworkSafetyTest
{
    #[Test]
    public function actualCredentialBearingCurlPreservesFramingResetSizeTlsAndDeadlineReasons(): void
    {
        $server = new RawHttpServer();
        $vault = new TokenVault($server->directory->path . '/key');
        try {
            $site = [...SiteRepository::defaults(), 'repository' => 'fixture/public', 'health_path' => '', 'version_path' => '',
                'endpoint_token' => $vault->encrypt(EndpointFixture::tokenA())];
            foreach (['invalid-hex' => 'invalid-response', 'long-hex' => 'invalid-response', 'overflow-hex' => 'invalid-response',
                'bad-separator' => 'invalid-response', 'incomplete' => 'invalid-response', 'reset' => 'network', 'large' => 'size'] as $mode => $reason) {
                $state = (new SiteChecker(new HttpClient(true), new FakeProvider(), $vault))->check([...$site, 'url' => $server->base . '/' . $mode]);
                Assert::same($state['online'], null);
                Assert::same($state['health_error_code'], $reason);
                Assert::false(str_contains($state['last_error'], EndpointFixture::tokenA()));
                Assert::same($state['latest_release'], 'v1.3.2');
            }
            $headers = ['Authorization: Bearer ' . EndpointFixture::tokenA()];
            $caught = null;
            try { (new HttpClient(true))->getBefore($server->base . '/stall', $headers, hrtime(true) + 100_000_000); }
            catch (HttpFailure $error) { $caught = $error->reason; unset($error); }
            Assert::same($caught, 'timeout');
        } finally { $vault = null; $server->close(); }
        // A plain HTTP server rejects the TLS handshake immediately. The raw
        // framing peer waits for a text request line and correctly times out.
        $directory = new TemporaryDirectory('tablo-endpoint-tls-');
        $peer = null;
        try {
            $peer = new TestServer($directory, dirname(__DIR__) . '/endpoint-router.php');
            $caught = null;
            try { (new HttpClient(true))->get(str_replace('http:', 'https:', $peer->base), $headers); }
            catch (HttpFailure $error) { $caught = $error->reason; unset($error); }
            Assert::same($caught, 'tls');
        } finally { $peer?->close(); $directory->close(); }
    }

    #[Test]
    public function credentialDoesNotBypassSsrfAllAddressValidationOrPostDnsDeadline(): void
    {
        $headers = ['Authorization: Bearer ' . EndpointFixture::tokenA()];
        foreach (['http://127.0.0.1/', 'http://10.1.2.3/', 'http://169.254.169.254/', 'http://[::1]/', 'http://[::ffff:8.8.8.8]/'] as $url) {
            $caught = null;
            try { (new HttpClient())->get($url, $headers); } catch (HttpFailure $error) { $caught = $error->reason; unset($error); }
            Assert::same($caught, 'ssrf');
        }
        $mixed = new class extends HttpClient {
            protected function resolve(#[\SensitiveParameter] string $host): array { return ['8.8.8.8', '127.0.0.1']; }
        };
        $caught = null;
        try { $mixed->get('https://mixed.example/', $headers); } catch (HttpFailure $error) { $caught = $error->reason; unset($error); }
        Assert::same($caught, 'ssrf', 'controlled resolver proves every answer is checked before native HTTP');
        $slow = new class extends HttpClient {
            protected function resolve(#[\SensitiveParameter] string $host): array { usleep(40000); return ['8.8.8.8']; }
        };
        $caught = null;
        try { $slow->getBefore('https://deadline.example/', $headers, hrtime(true) + 10_000_000); }
        catch (HttpFailure $error) { $caught = $error->reason; unset($error); }
        Assert::same($caught, 'timeout', 'controlled synchronous resolver overrun cannot start cURL');
    }
}
