<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Testo\Assert;
use Testo\Test;
use Testo\Lifecycle\BeforeTest;
use Testo\Lifecycle\AfterTest;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;

final class HttpClientTest
{
    private ?TemporaryDirectory $directory = null;
    private ?TestServer $server = null;

    #[BeforeTest]
    public function start(): void
    {
        $this->directory = new TemporaryDirectory('tablo-network-');
        try {
            $this->server = new TestServer($this->directory, dirname(__DIR__) . '/endpoint-router.php');
        } catch (\Throwable $error) {
            $this->directory->close();
            $this->directory = null;
            throw $error;
        }
    }

    #[AfterTest]
    public function stop(): void
    {
        $this->server?->close();
        $this->directory?->close();
        $this->server = null;
        $this->directory = null;
    }

    #[Test]
    public function checksRealCurlHealthAndVersion(): void
    {
        $site = ['url' => $this->server->base, 'health_path' => '/up', 'version_path' => '/version',
            'repository' => 'test/repo', 'branch' => 'main'];
        $state = (new SiteChecker(new HttpClient(true), new FakeProvider()))->check($site);
        Assert::true($state['online'] === 1 && $state['deployed_version'] === '1.3.1'
            && $state['deployed_commit'] === 'a61de82' && $state['last_error'] === null, 'Actual cURL health and version endpoints');
    }

    #[Test]
    public function doesNotFollowRedirects(): void
    {
        Assert::same((new HttpClient(true))->get($this->server->base . '/redirect')['status'], 302);
    }

    #[Test]
    public function refusesResponsesOverOneMegabyte(): void
    {
        $this->rejects('/large', 'size');
    }

    #[Test]
    public function boundsStalledEndpointTimeout(): void
    {
        $start = microtime(true);
        $this->rejects('/slow', 'timeout');
        Assert::true(microtime(true) - $start < 8, 'HTTP timeout is bounded');
    }

    #[Test]
    public function distinguishesRefusedTlsAndTruncatedResponses(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) { throw new RuntimeException($message); }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        foreach ([['http://' . $address, 'refused'], [str_replace('http:', 'https:', $this->server->base), 'tls']] as [$url, $reason]) {
            $error = null;
            try { (new HttpClient(true))->get($url); } catch (HttpFailure $caught) { $error = $caught; }
            Assert::instanceOf($error, HttpFailure::class);
            Assert::same($error->reason, $reason);
            Assert::false(str_contains($error->getMessage(), $url));
        }
        $this->rejects('/truncated', 'invalid-response');
    }

    private function rejects(string $path, string $reason): void
    {
        $error = null;
        try { (new HttpClient(true))->get($this->server->base . $path); } catch (RuntimeException $caught) { $error = $caught; }
        Assert::instanceOf($error, RuntimeException::class, 'Unsafe or stalled response refused');
        Assert::instanceOf($error, HttpFailure::class);
        Assert::same($error->reason, $reason);
    }
}
