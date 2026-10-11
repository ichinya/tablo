<?php
declare(strict_types=1);

namespace Tablo {
    final class EndpointNativeControl {
        public static string $fault = '';
        public static ?\WeakReference $handle = null;
        public static ?\WeakReference $callback = null;
        public static bool $originalTypeerror = false;
        public static bool $originalLeaked = false;
    }
    // Isolated actual native fault at the real HttpClient call site. The options
    // are not recorded: only booleans/class identity and weak references survive.
    function curl_setopt_array(#[\SensitiveParameter] \CurlHandle $handle, #[\SensitiveParameter] array $options): bool
    {
        if ((\Tablo\EndpointNativeControl::$fault ?? '') === 'callback') {
            \Tablo\EndpointNativeControl::$handle = \WeakReference::create($handle);
            \Tablo\EndpointNativeControl::$callback = \WeakReference::create($options[CURLOPT_WRITEFUNCTION]);
        }
        if ((\Tablo\EndpointNativeControl::$fault ?? '') !== 'setopt') { return \curl_setopt_array($handle, $options); }
        \Tablo\EndpointNativeControl::$handle = \WeakReference::create($handle);
        \Tablo\EndpointNativeControl::$callback = \WeakReference::create($options[CURLOPT_WRITEFUNCTION]);
        $options[CURLOPT_RESOLVE] = new \stdClass();
        try { return \curl_setopt_array($handle, $options); }
        catch (\Throwable $error) {
            \Tablo\EndpointNativeControl::$originalTypeerror = $error instanceof \TypeError;
            \Tablo\EndpointNativeControl::$originalLeaked = \Tablo\Tests\Support\TraceInspector::inspect($error, [\Tablo\Tests\Support\EndpointFixture::tokenA()])['leaked'];
            throw $error;
        }
    }
    function strlen(#[\SensitiveParameter] string $value): int
    {
        if ((\Tablo\EndpointNativeControl::$fault ?? '') === 'callback' && str_contains($value, \Tablo\Tests\Support\EndpointFixture::tokenA())) {
            throw new \TypeError('Fixed callback control.');
        }
        return \strlen($value);
    }
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '8192');
    function unprotectedEndpointControl(string $raw): never { throw new \RuntimeException('Fixed hostile control.'); }
    function protectedEndpointProbe(#[\SensitiveParameter] callable $action, #[\SensitiveParameter] array $markers): array
    {
        try { $action(); } catch (\Throwable $error) {
            $scan = \Tablo\Tests\Support\TraceInspector::inspect($error, $markers);
            return [...$scan, 'previous' => $error->getPrevious() !== null,
                'typed' => $error instanceof \Tablo\HttpFailure, 'reason' => $error instanceof \Tablo\HttpFailure ? $error->reason : null];
        }
        throw new \RuntimeException('Expected bounded failure.');
    }
    $mode = $argv[1] ?? 'safe';
    $method = str_starts_with($mode, 'redirect-') ? 'getEndpoint' : 'get';
    if ($method === 'getEndpoint') { $mode = substr($mode, 9); }
    $marker = \Tablo\Tests\Support\EndpointFixture::tokenA();
    if ($mode === 'fatal' || $mode === 'fatal-control') {
        if ($mode === 'fatal-control') { unprotectedEndpointControl($marker); }
        (new \Tablo\HttpClient(true))->{$method}('https://' . $marker . '@example.com/', ['Authorization: Bearer ' . $marker]);
        exit(1);
    }
    $records = [];
    if ($mode === 'native') {
        \Tablo\EndpointNativeControl::$fault = 'setopt';
        $scan = protectedEndpointProbe(static fn () => (new \Tablo\HttpClient(true))->{$method}('http://127.0.0.1/setopt-fault', ['Authorization: Bearer ' . $marker]), [$marker]);
        gc_collect_cycles();
        $records[] = [...$scan, 'original_typeerror' => \Tablo\EndpointNativeControl::$originalTypeerror,
            'original_leaked' => \Tablo\EndpointNativeControl::$originalLeaked, 'handle_released' => \Tablo\EndpointNativeControl::$handle->get() === null,
            'callback_released' => \Tablo\EndpointNativeControl::$callback->get() === null];
        \Tablo\EndpointNativeControl::$fault = '';
        $handle = curl_init();
        try { curl_setopt_array($handle, [CURLOPT_HTTPHEADER => new \stdClass(), $marker => $marker]); }
        catch (\Throwable $error) { $records[] = ['external_native_typeerror' => $error instanceof \TypeError,
            'external_native_leaked' => \Tablo\Tests\Support\TraceInspector::inspect($error, [$marker])['leaked']]; unset($error); }
        unset($handle);
        $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-endpoint-callback-');
        $server = null;
        try {
            $server = new \Tablo\Tests\Support\TestServer($directory, __DIR__ . '/endpoint-token-router.php', environment: ['TABLO_ENDPOINT_FIXTURE' => $directory->path]);
            \Tablo\EndpointNativeControl::$fault = 'callback';
            $scan = protectedEndpointProbe(static fn () => (new \Tablo\HttpClient(true))->{$method}($server->base . '/version?bad=1', ['Authorization: Bearer ' . $marker]), [$marker]);
            gc_collect_cycles();
            $records[] = [...$scan, 'callback_boundary' => true, 'handle_released' => \Tablo\EndpointNativeControl::$handle->get() === null,
                'callback_released' => \Tablo\EndpointNativeControl::$callback->get() === null];
            \Tablo\EndpointNativeControl::$fault = '';
            $handle = curl_init($server->base . '/version?bad=1');
            curl_setopt_array($handle, [CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 3, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $marker],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk): int { throw new \TypeError('Fixed raw callback control.'); }]);
            try { curl_exec($handle); }
            catch (\Throwable $error) { $records[] = ['raw_callback_typeerror' => $error instanceof \TypeError,
                'raw_callback_leaked' => \Tablo\Tests\Support\TraceInspector::inspect($error, [$marker])['leaked']]; unset($error); }
            unset($handle);
        } finally { $server?->close(); $directory->close(); }
    } else {
        // Closures are deliberately opaque to the inspector. The returned trace
        // remains untrimmed and its caller arguments are protected independently.
        $records[] = protectedEndpointProbe(static fn () => (new \Tablo\HttpClient())->{$method}('https://' . $marker . '@example.com/', ['Authorization: Bearer ' . $marker]), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => (new \Tablo\HttpClient())->getBefore('file:///' . $marker, ['Authorization: Bearer ' . $marker], hrtime(true)+1_000_000_000), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => \Tablo\SiteRepository::validateEndpointToken(['raw' => $marker]), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => \Tablo\SiteRepository::normalize(['url' => 'https://' . $marker . '@example.com/', 'endpoint_token' => $marker]), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => \Tablo\JsonField::decode($marker), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => \Tablo\JsonField::extract((object) ['secret' => $marker], '$.missing'), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => \Tablo\JsonField::number($marker), [$marker]);
        $records[] = protectedEndpointProbe(static fn () => (new \Tablo\TokenVault('unused'))->decrypt($marker), [$marker]);
        try { unprotectedEndpointControl($marker); }
        catch (\Throwable $error) { $records[] = ['raw_control_leaked' => \Tablo\Tests\Support\TraceInspector::inspect($error, [$marker])['leaked']]; unset($error); }
        $previous = new \RuntimeException($marker);
        $private = new class($marker) { public function __construct(#[\SensitiveParameter] private string $secret) {} };
        $scan = \Tablo\Tests\Support\TraceInspector::inspectArguments([$private, $previous], [$marker]);
        $records[] = ['private_previous_control_leaked' => $scan['leaked'], 'opaque_complete' => \Tablo\Tests\Support\TraceInspector::inspectArguments([static fn () => $marker], [$marker])['complete']];
        unset($private, $previous);
        $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-endpoint-sql-');
        $db = $vault = $sites = null;
        try {
            $db = \Tablo\Database::connect(':memory:');
            $vault = new \Tablo\TokenVault($directory->path . '/key');
            $sites = new \Tablo\SiteRepository($db, $vault);
            $input = [...\Tablo\Tests\Support\UnitFixtures::site(), 'endpoint_token' => $marker];
            $id = $sites->save($input);
            $site = $sites->find($id);
            $cipher = $site['endpoint_token'];
            $db->exec("CREATE TRIGGER endpoint_refusal BEFORE UPDATE ON sites BEGIN SELECT RAISE(ABORT, 'Fixed storage control.'); END");
            foreach ([static fn () => $sites->save($input, $id), static fn () => $sites->storeCheck($site, ['online' => 1])] as $action) {
                $scan = protectedEndpointProbe($action, [$marker, $cipher]);
                $records[] = [...$scan, 'sql_boundary' => true];
            }
            try { $db->prepare('UPDATE sites SET endpoint_token = ? WHERE id = ?')->execute([$cipher, $id]); }
            catch (\Throwable $error) { $records[] = ['raw_sql_control_leaked' => \Tablo\Tests\Support\TraceInspector::inspect($error, [$cipher])['leaked']]; unset($error); }
            unset($action, $site, $input, $cipher);
        } finally { $sites = $vault = $db = null; $directory->close(); }
    }
    echo json_encode($records, JSON_THROW_ON_ERROR) . "\n";
}
