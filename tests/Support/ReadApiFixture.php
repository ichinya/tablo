<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\ReadApiToken;
use Testo\Assert;

final class ReadApiFixture
{
    public readonly WebFixture $web;
    public function __construct(array $environment = [])
    {
        $this->web = new WebFixture(router: dirname(__DIR__) . '/fixtures/read-api-router.php', environment: $environment);
    }

    public function rotate(string $csrf): string
    {
        $response = $this->web->request('/settings/read-api-token/rotate', ['_csrf' => $csrf]);
        Assert::same($response['status'], 200, 'Committed rotation must directly render the one-time HTML response.');
        Assert::true(str_contains(strtolower($response['headers']), 'cache-control: no-store, private'));
        $match = [];
        Assert::same(preg_match('/<code id="read-api-token">(tablo_read_v1_[0-9a-f]{64})<\/code>/', $response['body'], $match), 1);
        return FixtureValue::string($match[1] ?? null);
    }

    /** @param array{headers?: list<string>, cookies?: CookiePolicy} $options
     * @return array{status: int, headers: string, body: string} */
    public function api(string $path = '/api/v1/sites', #[\SensitiveParameter] ?string $secret = null, string $method = 'GET', ?string $body = null, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        $cookies = ($options['cookies'] ?? CookiePolicy::Omitted) === CookiePolicy::Retained;
        $curl = curl_init($this->web->server->base . $path);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize read API fixture.'); }
        try {
            if ($secret !== null) { $headers[] = 'Authorization: Bearer ' . $secret; }
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROXY => '',
                CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_COOKIEFILE => $cookies ? $this->web->directory->path . '/cookies' : '']);
            if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
            if ($method === 'HEAD') { curl_setopt($curl, CURLOPT_NOBODY, value: true); }
            $raw = curl_exec($curl);
            if (!is_string($raw)) { throw new \RuntimeException('Read API fixture transport failed.'); }
            $size = FixtureValue::integer(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
            $status = FixtureValue::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
            // Never persist Authorization or the one-time issuance body in ordinary fixture logs.
            SessionEvidence::record('read-api-request', ['path' => $path, 'method' => $method, 'status' => $status,
                'bodySha256' => hash('sha256', $body ?? ''), 'authenticatedInput' => $secret !== null]);
            return ['status' => $status, 'headers' => substr($raw, offset: 0, length: $size), 'body' => substr($raw, $size)];
        } finally { unset($curl); }
    }

    public static function error(array $response, int $status, string $code): void
    {
        Assert::same($response['status'] ?? null, $status);
        $body = FixtureValue::string($response['body'] ?? null);
        Assert::same(json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR), ['api_version' => 1, 'error' => ['code' => $code, 'message' => match ($code) {
            'unauthorized' => 'Authentication required.', 'invalid_parameter' => 'Invalid request.', 'not_found' => 'Not found.',
            'method_not_allowed' => 'Method not allowed.', default => 'Service unavailable.',
        }]]);
        self::headers($response);
    }

    public static function headers(array $response): void
    {
        $headers = strtolower(FixtureValue::string($response['headers'] ?? null));
        foreach (['content-type: application/json; charset=utf-8','cache-control: no-store, private','x-content-type-options: nosniff'] as $required) { Assert::true(str_contains($headers, $required), $required); }
        foreach (['location:','set-cookie:','access-control-allow-origin:'] as $forbidden) { Assert::false(str_contains($headers, $forbidden)); }
    }

    public static function wrong(): string { return ReadApiToken::generate(); }
    public function close(): void
    {
        $this->web->directory->cleanup(function (): void {
            $this->web->reopenDatabase(); $this->web->server->close();
            read_api_fixture_close_port($this->web->server->port);
            $this->web->close();
        });
    }
}

function read_api_fixture_close_port(int $port): void
{
    $socket = \Tablo\NativeWarning::ignore(static fn (): mixed => fsockopen('127.0.0.1', $port, timeout: .1));
    if (is_resource($socket)) { fclose($socket); throw new \RuntimeException('Owned read API server port remains open.'); }
    SessionEvidence::record('read-api-port-closed', ['port' => $port, 'closed' => true]);
}
