<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class ContainerHealthTest
{
    private static function client(string $path, string $directory, array $extra = [], bool $noIni = false): array
    {
        $argv = $noIni ? [PHP_BINARY, '-n', $path] : [PHP_BINARY, $path];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $directory . '/client.out', 'w'],
            2 => ['file', $directory . '/client.err', 'w']], $pipes, null, array_replace(getenv(), $extra));
        Assert::true(is_resource($process));
        fclose($pipes[0]);
        $started = microtime(true);
        $exit = proc_close($process);
        return [$exit, file_get_contents($directory . '/client.out'), file_get_contents($directory . '/client.err'), microtime(true) - $started];
    }

    #[Test]
    public function realCurlRejectsIncorrectResponsesAndBoundsTransfer(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/deploy/healthcheck.php');
        foreach (['ok' => 0, 'redirect' => 1, 'login' => 1, 'static' => 1, 'raw-php' => 1, 'large-body' => 1, 'large-header' => 1, 'delayed-body' => 1] as $mode => $exit) {
            $directory = new TemporaryDirectory('tablo-container-health-client-');
            $router = $directory->path . '/router.php';
            file_put_contents($router, <<<'PHP'
<?php
$mode = getenv('HEALTH_FIXTURE_MODE');
file_put_contents(getenv('HEALTH_FIXTURE_LOG'), json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'target' => $_SERVER['REQUEST_URI'], 'cookie' => isset($_SERVER['HTTP_COOKIE']), 'credentials' => isset($_SERVER['HTTP_AUTHORIZATION'])]));
if ($mode === 'redirect') { header('Location: /login', true, 302); echo "ok\n"; return; }
if ($mode === 'login') { echo '<html>login</html>'; return; }
if ($mode === 'static') { echo 'ok'; return; }
if ($mode === 'raw-php') { echo '<?php echo "ok\\n";'; return; }
if ($mode === 'large-body') { echo str_repeat('x', 17); return; }
if ($mode === 'large-header') { header('X-Oversize: ' . str_repeat('x', 4096)); echo "ok\n"; return; }
if ($mode === 'delayed-body') { header('Content-Length: 3'); flush(); usleep(2500000); }
echo "ok\n";
PHP);
            $log = $directory->path . '/request.json';
            $server = new TestServer($directory, $router, environment: ['HEALTH_FIXTURE_MODE' => $mode, 'HEALTH_FIXTURE_LOG' => $log]);
            try {
                // Only the test transport is remapped. Options/callbacks/exits execute the real client and ext-curl.
                $shim = ' namespace HealthClientFixture; use \\CurlHandle; use \\RuntimeException; use \\Throwable; function curl_init(string $url) { if ($url !== "http://127.0.0.1:80/_tablo/health") { throw new RuntimeException(); } return \\curl_init(' . var_export($server->base . '/_tablo/health', true) . '); }';
                $client = $directory->path . '/client.php';
                file_put_contents($client, str_replace('declare(strict_types=1);', 'declare(strict_types=1);' . $shim, $source));
                [$actual, $out, $err, $elapsed] = self::client($client, $directory->path, ['HTTP_PROXY' => 'http://127.0.0.1:1', 'http_proxy' => 'http://127.0.0.1:1', 'ALL_PROXY' => 'http://127.0.0.1:1', 'NO_PROXY' => '']);
                Assert::same($actual, $exit, $mode);
                Assert::same($out, $exit === 0 ? "healthy\n" : "unavailable\n", $mode);
                Assert::same($err, '', $mode);
                Assert::true($elapsed < 4, 'bounded client wall time');
                $request = json_decode(file_get_contents($log), true);
                Assert::same($request, ['method' => 'GET', 'target' => '/_tablo/health', 'cookie' => false, 'credentials' => false]);
            } finally { $server->close(); $directory->close(); }
        }
    }

    #[Test]
    public function missingCurlFailsWithDirectSafeExit(): void
    {
        $directory = new TemporaryDirectory('tablo-container-health-no-curl-');
        try {
            [$exit, $out, $err] = self::client(dirname(__DIR__, 2) . '/deploy/healthcheck.php', $directory->path, noIni: true);
            Assert::same($exit, 1);
            Assert::same($out, "unavailable\n");
            Assert::same($err, '');
        } finally { $directory->close(); }
    }
}
