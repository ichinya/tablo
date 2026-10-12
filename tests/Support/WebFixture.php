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
                'TABLO_TEST_TRUSTED_PROXIES' => $trustedProxies,
                // Assert that a legacy environment token cannot grant private repository access.
                'GITHUB_TOKEN' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'),
            ], $environment), host: $host);
        } catch (\Throwable $error) {
            $this->directory->close();
            throw $error;
        }
    }

    public function request(string $path, ?array $data = null, ?WebRequestOptions $options = null): array
    {
        $options ??= new WebRequestOptions();
        $cookie = $options->cookies === CookiePolicy::Retained;
        $curl = curl_init(($options->base ?? $this->server->base) . $path);
        $cookies = $this->directory->path . '/' . $options->jar;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $cookie ? $cookies : '', CURLOPT_COOKIEJAR => $cookie ? $cookies : null]);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $options->headers);
        if ($options->source !== null) { curl_setopt($curl, CURLOPT_INTERFACE, $options->source); }
        if ($data !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]); }
        $raw = curl_exec($curl);
        if ($raw === false) { throw new RuntimeException('HTTP fixture request failed: ' . curl_error($curl) . "\n" . $this->server->diagnostics()); }
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, offset: 0, length: $size), 'body' => substr($raw, $size)];
    }

    public static function csrf(array $response): string
    {
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
