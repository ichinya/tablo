<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Testo\Assert;

/** Genuine index in a copied installation; never loads an installation .env. */
final class HstsFixture
{
    public readonly TemporaryDirectory $directory;
    public readonly string $source;
    public readonly TestServer $server;

    public function __construct(array $environment = [], string $autoload = 'complete', string $dotenv = '')
    {
        $this->directory = new TemporaryDirectory('tablo-hsts-');
        $this->source = $this->directory->path . '/source';
        $root = dirname(__DIR__, levels: 2);
        try {
            foreach (['app', 'public', 'views', 'database'] as $name) { self::copy($root . '/' . $name, $this->source . '/' . $name); }
            mkdir($this->source . '/vendor');
            if ($autoload !== 'absent') { file_put_contents($this->source . '/vendor/autoload.php', self::autoload($root, $autoload)); }
            hsts_fixture_owner($root, $this->source, $autoload);
            file_put_contents($this->source . '/.env', $dotenv);
            $this->server = new TestServer($this->directory, $root . '/tests/fixtures/hsts-router.php', $this->source . '/public', array_replace([
                'TABLO_HSTS_SOURCE' => $this->source, 'TABLO_DB' => $this->source . '/storage/fixture.sqlite',
                'TABLO_TOKEN_KEY_FILE' => '', 'TABLO_HSTS_MODE' => 'off', 'TABLO_HSTS_MAX_AGE' => '',
                'TABLO_TRUSTED_PROXIES' => '', 'TABLO_COOKIE_SECURE' => '0',
            ], $environment));
        } catch (\Throwable $error) { $this->directory->retainFailure($error); throw $error; }
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, permissions: 0o700, recursive: true);
        foreach (new \DirectoryIterator($from) as $entry) {
            if ($entry->isDot()) { continue; }
            $target = $to . '/' . $entry->getFilename();
            if ($entry->isDir()) { self::copy($entry->getPathname(), $target); continue; }
            copy($entry->getPathname(), $target);
        }
    }

    private static function autoload(string $root, string $fault): string
    {
        $injection = match ($fault) {
            'branches' => 'require dirname(__DIR__)."/provider-stub.php";',
            'output' => 'echo "private-hsts-canary";',
            'headers' => 'header("Strict-Transport-Security: max-age=999"); header("Set-Cookie: private=canary"); header("Location: /private"); header("Access-Control-Allow-Origin: *");',
            'status' => 'http_response_code(201);',
            'closed' => 'ob_end_clean();',
            'buffers' => 'for ($i=0;$i<40;++$i) { ob_start(); }',
            'throw' => 'throw new RuntimeException("private-hsts-canary");',
            default => '',
        };
        return '<?php ' . $injection . ' $vendor=' . var_export($root . '/vendor', return: true) . ';'
            . 'require_once $vendor . "/composer/ClassLoader.php"; $loader=new \\Composer\\Autoload\\ClassLoader($vendor);'
            . 'foreach (require $vendor . "/composer/autoload_psr4.php" as $prefix=>$paths) { $loader->setPsr4($prefix,$paths); }'
            . '$map=require $vendor . "/composer/autoload_classmap.php";'
            . 'foreach ($map as $class=>$file) { if(str_starts_with($class,"Tablo\\\\")) { $map[$class]=dirname(__DIR__)."/app/".basename($file); } }'
            . '$loader->addClassMap($map); $loader->register();'
            . 'foreach (require $vendor . "/composer/autoload_files.php" as $file) {'
            . 'require str_ends_with($file,"/app/bootstrap.php") ? dirname(__DIR__)."/app/bootstrap.php" : $file; } return $loader;';
    }

    /** @param list<string> $headers
     * @return array{status: int, headers: string, body: string} */
    public function request(string $path, ?array $data = null, array $headers = [], string $method = 'GET'): array
    {
        $curl = curl_init($this->server->base . $path);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize owned HSTS request.'); }
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8, CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => $headers, CURLOPT_COOKIEFILE => $this->directory->path . '/cookies', CURLOPT_COOKIEJAR => $this->directory->path . '/cookies']);
            if ($data !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]); }
            if ($data === null && $method !== 'GET') { curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method); }
            $raw = curl_exec($curl);
            if (!is_string($raw)) { throw new \RuntimeException('Owned HSTS request failed.'); }
            $size = FixtureValue::integer(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
            return ['status' => FixtureValue::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE)),
                'headers' => substr($raw, offset: 0, length: $size), 'body' => substr($raw, $size)];
        } finally { unset($curl); }
    }

    public function authenticate(): string
    {
        $setup = $this->request('/setup');
        Assert::same($this->request('/setup', ['_csrf' => WebFixture::csrf($setup), 'password' => FixtureCredentials::value('fixture-password'),
            'confirmation' => FixtureCredentials::value('fixture-password')])['status'], 303);
        return WebFixture::csrf($this->request('/'));
    }

    /** @param array{headers: string, ...} $response */
    public static function header(array $response, ?string $value): void
    {
        $matches = [];
        preg_match_all('/^Strict-Transport-Security:[ \t]*([^\r\n]*)/mi', $response['headers'], $matches);
        Assert::same($matches[1] ?? [], $value === null ? [] : [$value]);
    }

    public function close(): void
    {
        $this->directory->cleanup($this->server->close(...));
        $this->directory->cleanup($this->directory->close(...));
    }
}

/** Alter only the fresh fixture before server startup, avoiding cached absent owners. */
function hsts_fixture_owner(string $root, string $source, string $fault): void
{
    if ($fault === 'missing-policy') { unlink($source . '/app/HstsPolicy.php'); }
    if ($fault === 'missing-api') { unlink($source . '/app/ReadApi.php'); }
    if ($fault === 'branches') { hsts_fixture_provider($root, $source); }
}

/** Stub only the outbound transport; the index, Web, sessions and provider remain real. */
function hsts_fixture_provider(string $root, string $source): void
{
    $original = \Tablo\RequiredFile::bytes(file_get_contents($root . '/app/HttpClient.php'));
    Assert::same(substr_count($original, needle: 'class HttpClient'), 1);
    $base = str_replace('class HttpClient', replace: 'class HstsFixtureHttpClientBase', subject: $original);
    $log = var_export($source . '/provider-calls.jsonl', return: true);
    $stub = <<<'PHP'

class HttpClient extends HstsFixtureHttpClientBase
{
    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        if (!str_starts_with($url, 'https://api.github.com/repos/fixture/public')) {
            throw new \RuntimeException('Unexpected isolated provider request.');
        }
        $path = parse_url($url, PHP_URL_PATH);
        file_put_contents(PROVIDER_LOG, json_encode(['path' => $path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        $body = str_ends_with($path, '/branches') ? [['name' => 'main', 'commit' => ['sha' => str_repeat('a', 40)]]]
            : ['private' => false, 'default_branch' => 'main'];
        return ['status' => 200, 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'headers' => [], 'time_ms' => 1];
    }
}
PHP;
    Assert::notSame(file_put_contents($source . '/provider-stub.php', $base . str_replace('PROVIDER_LOG', $log, $stub)), false);
}
