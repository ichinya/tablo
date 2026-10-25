<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use RuntimeException;
use Testo\Assert;

final class WebFixture
{
    public readonly TemporaryDirectory $directory;
    public readonly TestServer $server;
    private ?PDO $database = null;

    public function __construct(bool $allowPrivateNetwork = false, string $trustedProxies = '', string $host = '127.0.0.1', ?string $router = null, array $environment = [])
    {
        $this->directory = new TemporaryDirectory('tablo-http-');
        $root = dirname(__DIR__, levels: 2);
        try {
            $this->server = new TestServer($this->directory, $router ?? $root . '/tests/web-router.php', $root . '/public', array_replace([
                'TABLO_DB' => $this->directory->path . '/test.sqlite',
                'TABLO_TEST_RUNTIME' => $this->directory->path . '/runtime',
                'TABLO_TEST_GITHUB_LOG' => $this->directory->path . '/github-calls.log',
                'TABLO_ALLOW_PRIVATE_NETWORK' => $allowPrivateNetwork ? '1' : '0', 'TABLO_COOKIE_SECURE' => '0',
                'TABLO_TRUSTED_PROXIES' => $trustedProxies,
                'TABLO_HSTS_MODE' => 'off', 'TABLO_HSTS_MAX_AGE' => '',
                'TABLO_TEST_TRUSTED_PROXIES' => $trustedProxies,
                // Assert that a legacy environment token cannot grant private repository access.
                'GITHUB_TOKEN' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'),
            ], $environment), host: $host);
        } catch (\Throwable $error) {
            $this->directory->close();
            throw $error;
        }
    }

    /** @return array{status: int, headers: string, body: string} */
    public function request(string $path, ?array $data = null, ?WebRequestOptions $options = null): array
    {
        $options ??= new WebRequestOptions();
        $cookie = $options->cookies === CookiePolicy::Retained;
        $curl = curl_init(($options->base ?? $this->server->base) . $path);
        if ($curl === false) { throw new RuntimeException('Cannot initialize HTTP fixture request.'); }
        $cookies = $this->directory->path . '/' . $options->jar;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $cookie ? $cookies : '', CURLOPT_COOKIEJAR => $cookie ? $cookies : null]);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $options->headers);
        if ($options->source !== null) { curl_setopt($curl, CURLOPT_INTERFACE, $options->source); }
        if ($data !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]); }
        $raw = curl_exec($curl);
        if (!is_string($raw)) { throw new RuntimeException('HTTP fixture request failed: ' . curl_error($curl) . "\n" . $this->server->diagnostics()); }
        $size = FixtureValue::integer(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
        $status = FixtureValue::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
        SessionEvidence::record('http-request', ['fixture' => $this->directory->path, 'path' => $path,
            'method' => $data === null ? 'GET' : 'POST', 'inputKeys' => $data === null ? [] : array_keys($data),
            'inputSha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'status' => $status,
            'jar' => $options->jar, 'headers' => $options->headers]);
        return ['status' => $status, 'headers' => substr($raw, offset: 0, length: $size), 'body' => substr($raw, $size)];
    }

    /** @param array{body: string, ...} $response */
    public static function csrf(array $response): string
    {
        $match = [];
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $response['body'], $match);
        if (!(($match[1] ?? null) !== null)) { throw new RuntimeException('CSRF missing: ' . substr($response['body'], offset: 0, length: 250)); }
        return $match[1];
    }

    public function authenticate(): string
    {
        $setup = $this->request('/setup');
        Assert::same($this->request('/setup', ['_csrf' => self::csrf($setup), 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'),
            'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')])['status'], 303, 'Fixture administrator setup');
        return self::csrf($this->request('/'));
    }

    public function database(): PDO
    {
        if ($this->database === null) {
            $this->database = new PDO('sqlite:' . $this->directory->path . '/test.sqlite', options:
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $this->database->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        }
        return $this->database;
    }

    public function reopenDatabase(): void
    {
        $this->database = null;
    }

    public function close(): void
    {
        $this->database = null;
        $this->server->close();
        $this->directory->close();
    }
}
