<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class FixtureCleanupTest
{
    #[Test]
    public function releasesDatabaseServerPortAndNestedFilesAfterFailure(): void
    {
        $web = new WebFixture();
        $path = $web->directory->path;
        $port = $web->server->port;
        try {
            try {
                $web->authenticate();
                $web->database();
                throw new RuntimeException('Simulated scenario failure');
            } finally { $web->close(); }
        } catch (RuntimeException $error) { Assert::same($error->getMessage(), 'Simulated scenario failure'); }
        $web->close();
        Assert::false(is_dir($path), 'Database, sessions, cookies and Volt cache removed');
        $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
        Assert::true(is_resource($socket), 'Server exited and port released');
        if (is_resource($socket)) { fclose($socket); }
    }

    #[Test]
    public function reportsStartupFailureAndAllowsPartialFixtureCleanup(): void
    {
        $directory = new TemporaryDirectory('tablo-startup-');
        $path = $directory->path;
        $error = null;
        try {
            try {
                new TestServer($directory, dirname(__DIR__) . '/endpoint-router.php', $path . '/missing-root');
            } catch (RuntimeException $caught) { $error = $caught; }
        } finally { $directory->close(); }
        Assert::instanceOf($error, RuntimeException::class);
        Assert::true(str_contains($error->getMessage(), 'missing-root'), 'Startup diagnostic includes server log');
        Assert::false(is_dir($path));
    }
}
