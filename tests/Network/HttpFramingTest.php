<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\RawHttpServer;
use Testo\Assert;
use Testo\Test;

final class HttpFramingTest
{
    #[Test]
    public function distinguishesMalformedFramingFromActualReceiveReset(): void
    {
        $server = new RawHttpServer();
        try {
            foreach (['invalid-hex' => 'invalid-response', 'long-hex' => 'invalid-response',
                'overflow-hex' => 'invalid-response', 'bad-separator' => 'invalid-response',
                'incomplete' => 'invalid-response', 'reset' => 'network'] as $mode => $reason) {
                // Confirm that the negative control really reaches errno 56.
                $curl = curl_init($server->base . '/' . $mode);
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 3]);
                Assert::same(curl_exec($curl), false, $mode);
                Assert::same(curl_errno($curl), $mode === 'incomplete' ? CURLE_PARTIAL_FILE : CURLE_RECV_ERROR, $mode);
                unset($curl);
                foreach (['', '/up'] as $path) {
                    $site = array_replace(SiteRepository::defaults(), ['url' => $server->base . '/' . $mode,
                        'health_path' => $path, 'version_path' => '/version', 'repository' => 'fixture/public']);
                    $state = (new SiteChecker(new HttpClient(allowPrivate: true), new FakeProvider()))->check($site);
                    Assert::same($state['online'], null, $mode);
                    Assert::same($state['health_error_code'], $reason, $mode);
                    Assert::same($state['health_http_status'], null, $mode);
                    Assert::same($state['response_time_ms'], null, $mode);
                    Assert::same($state['deployed_version'], '1.3.1', $mode);
                    Assert::same($state['deployed_commit'], 'a61de82', $mode);
                    Assert::same($state['open_issues'], 4, $mode);
                    Assert::same($state['latest_commit'], str_repeat('a', 40), $mode);
                    Assert::same($state['last_error'], 'Health: ' . HttpFailure::MESSAGES[$reason], $mode);
                }
            }
        } finally { $server->close(); }
    }

    #[Test]
    public function acceptsValidChunksAndBoundsDecodedBody(): void
    {
        $server = new RawHttpServer();
        try {
            $response = (new HttpClient(allowPrivate: true))->get($server->base . '/valid');
            Assert::same($response['status'], 200);
            Assert::same($response['body'], 'hello');
            $failure = null;
            try { (new HttpClient(allowPrivate: true))->get($server->base . '/large'); }
            catch (HttpFailure $error) { $failure = $error; }
            Assert::instanceOf($failure, HttpFailure::class);
            Assert::same($failure->reason, 'size');
        } finally { $server->close(); }
    }

    #[Test]
    public function releasesTimedOutConnectionAndFixturePort(): void
    {
        $server = new RawHttpServer();
        $base = $server->base;
        $directory = $server->directory->path;
        try {
            $started = microtime(true);
            $failure = null;
            try { (new HttpClient(allowPrivate: true))->get($base . '/stall'); }
            catch (HttpFailure $error) { $failure = $error; }
            Assert::instanceOf($failure, HttpFailure::class);
            Assert::same($failure->reason, 'timeout');
            Assert::true(microtime(true) - $started < 8, 'HTTP deadline remains bounded');
            $deadline = microtime(true) + 1;
            while (!is_file($directory . '/disconnected') && microtime(true) < $deadline) { usleep(20000); clearstatcache(); }
            Assert::same(file_get_contents($directory . '/disconnected'), '1', 'Client released the TCP connection');
        } finally { $server->close(); }
        Assert::false(is_dir($directory));
        $socket = stream_socket_server(str_replace('http:', 'tcp:', $base), $errno, $error);
        Assert::true(is_resource($socket), 'Owned peer exited and released its port');
        if (is_resource($socket)) { fclose($socket); }
    }
}
