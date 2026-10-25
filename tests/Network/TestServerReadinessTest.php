<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;
use Throwable;

final class TestServerReadinessTest
{
    #[Test]
    public function liveForeignListenerDoesNotEstablishOwnedServerReadiness(): void
    {
        $directory = new TemporaryDirectory('tablo-readiness-');
        $socket = null;
        try {
        $errno = 0; $error = '';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) { throw new RuntimeException('Cannot create the owned foreign-listener control'); }
        $address = stream_socket_get_name($socket, remote: false);
        if ($address === false) { throw new RuntimeException('Cannot identify the owned control port'); }
        $port = (int) substr($address, (int) strrpos($address, needle: ':') + 1);
        $root = dirname(__DIR__, levels: 2);
        // Only the allocation and launch adapters are doubled in an isolated PHP
        // process. The real TestServer readiness/closure code sees an owned live
        // non-listening child and a distinct, genuinely reachable foreign socket.
        $capsule = <<<'PHP'
<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
final class ServerPort { public static function allocate(string $host): int { return __PORT__; } }
final class ServerCommand {
    public static function create(string $router, ?string $documentRoot, string $address, string $errorLog): array
    { return [PHP_BINARY, __IDLE__]; }
}
require __ROOT__ . '/vendor/autoload.php';
$directory = new TemporaryDirectory('tablo-owned-not-server-');
$server = null;
try {
    try { $server = new TestServer($directory, __FILE__); echo "accepted\n"; }
    catch (\RuntimeException) { echo "refused\n"; }
} finally { $server?->close(); $directory->close(); }
PHP;
        $capsule = str_replace(['__ROOT__', '__PORT__', '__IDLE__'], [var_export($root, return: true),
            (string) $port, var_export(dirname(__DIR__) . '/fixtures/server-readiness-idle.php', return: true)], $capsule);
        $script = $directory->path . '/probe.php';
            Assert::same(file_put_contents($script, $capsule), strlen($capsule));
            $result = Subprocess::run([PHP_BINARY, $script], $directory, environment: []);
            Assert::same($result['exit_code'], 0);
            Assert::same($result['stderr'], '');
            Assert::same($result['stdout'], "refused\n", 'A reachable foreign port cannot prove ownership of readiness');
            Assert::true(is_resource($socket), 'The failed launch does not close the independently owned listener');
            $peer = stream_socket_client('tcp://' . $address, $errno, $error, timeout: 1);
            Assert::true(is_resource($peer), 'The foreign listener remains reachable after owned-child cleanup');
            if (is_resource($peer)) { fclose($peer); }
        } catch (Throwable $primary) { $directory->retainFailure($primary); throw $primary; }
        finally { if (is_resource($socket)) { fclose($socket); } $directory->close(); }
    }

    #[Test]
    public function genuinePhpServerReadinessAndStartupRefusalStillOwnTheirChildren(): void
    {
        $directory = new TemporaryDirectory('tablo-genuine-readiness-');
        $server = null;
        try {
            $errno = 0; $error = '';
            $server = new TestServer($directory, dirname(__DIR__) . '/endpoint-router.php');
            $connection = stream_socket_client(str_replace('http://', replace: 'tcp://', subject: $server->base), $errno, $error, timeout: 1);
            Assert::true(is_resource($connection));
            if (is_resource($connection)) { fclose($connection); }
            Assert::true(str_contains($server->diagnostics(), 'Development Server (' . $server->base . ') started'));
        } catch (Throwable $primary) { $directory->retainFailure($primary); throw $primary; }
        finally { $server?->close(); $directory->close(); }
    }
}
