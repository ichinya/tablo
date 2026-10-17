<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\HstsFixture;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

#[Test]
function hsts_real_http_raw_peer_and_duplicate_fields_are_observed_at_the_native_sapi(): void
{
    $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1']);
    try {
        foreach ([['https', 'max-age=300'], [" \thTtPs\t ", 'max-age=300'], ['https,https', null], ['https, http', null],
            ["https\r\nX-Forwarded-Proto: https", null], ["https\r\nX-Forwarded-Proto: http", null]] as [$proto, $expected]) {
            $response = hsts_raw_request($fixture->server, $proto); Assert::same($response['status'], 401); HstsFixture::header($response, $expected);
        }
        $untrusted = hsts_raw_request($fixture->server, proto: 'https', peer: '127.0.0.2');
        Assert::same($untrusted['status'], 401); HstsFixture::header($untrusted, null);
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $fixture->close(); }
}

#[Test]
function hsts_plain_http_edge_overwrites_spoofed_external_scheme_and_direct_mode_ignores_it(): void
{
    $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1']);
    $directory = new TemporaryDirectory('tablo-hsts-edge-'); $edge = null;
    try {
        $edge = new TestServer($directory, dirname(__DIR__) . '/fixtures/hsts-transport.php', environment: [
            'TABLO_HSTS_BACKEND' => $fixture->server->base, 'TABLO_HSTS_EDGE_LOG' => $directory->path . '/edge.jsonl']);
        $response = hsts_raw_request($edge, proto: "https\r\nX-Forwarded-Proto: https");
        Assert::same($response['status'], 401); HstsFixture::header($response, null);
        $record = \Tablo\RequiredFile::bytes(file_get_contents($directory->path . '/edge.jsonl'));
        Assert::true(str_contains($record, '"forwarded":"http"'));
        Assert::true(str_contains($record, '"transport":"plain-http"'));
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); $directory->retainFailure($error); throw $error; }
    finally { $directory->cleanup(static function () use ($edge): void { $edge?->close(); }); $directory->cleanup($directory->close(...)); $fixture->close(); }
    $direct = new HstsFixture(['TABLO_HSTS_MODE' => 'direct', 'TABLO_HSTS_MAX_AGE' => '300']);
    try { $response = hsts_raw_request($direct->server, proto: 'https'); Assert::same($response['status'], 401); HstsFixture::header($response, null); }
    finally { $direct->close(); }
}

/** @return array{status: int, headers: string, body: string} */
function hsts_raw_request(TestServer $server, string $proto, string $peer = '127.0.0.1'): array
{
    $context = stream_context_create(['socket' => ['bindto' => $peer . ':0']]);
    $number = 0; $message = '';
    $socket = stream_socket_client('tcp://127.0.0.1:' . $server->port, $number, $message, timeout: 8, context: $context);
    if ($socket === false) { throw new \RuntimeException('Cannot open owned raw HSTS client.'); }
    try {
        stream_set_timeout($socket, seconds: 8);
        $request = "GET /api/v1/sites HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Forwarded-Proto: " . $proto . "\r\nConnection: close\r\n\r\n";
        Assert::same(fwrite($socket, $request), strlen($request));
        $raw = stream_get_contents($socket, length: 262_144);
        if (!is_string($raw)) { throw new \RuntimeException('Owned raw HSTS read failed.'); }
        Assert::false(stream_get_meta_data($socket)['timed_out']);
        $parts = explode("\r\n\r\n", $raw, limit: 2); $headers = $parts[0]; $match = [];
        Assert::same(preg_match('~^HTTP/1\.[01] ([0-9]{3}) ~D', $headers, $match), 1);
        return ['status' => (int) ($match[1] ?? '0'), 'headers' => $headers, 'body' => $parts[1] ?? ''];
    } finally { fclose($socket); }
}
