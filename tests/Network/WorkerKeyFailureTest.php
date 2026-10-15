<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\WorkerLock;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class WorkerKeyFailureTest extends WorkerFailureFixtureCase
{


    #[Test]
    public function realCliRefusesMissingCorruptSharedKeyAndRemovalDuringActualHealthRequest(): void
    {
        foreach (['missing', 'corrupt', 'empty', 'live', 'live-replacement'] as $kind) {
            $directory = new TemporaryDirectory('tablo-worker-key-r1-');
            $server = null; $child = null;
            try {
                $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                    environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
                $db = Database::connect($directory->path . '/db.sqlite');
                $live = str_starts_with($kind, 'live');
                self::sites($db, $directory, $server->base, $live ? '/barrier' : '/up');
                $key = $directory->path . '/github-token.key';
                $hash = hash_file('sha256', $key);
                if (!$live) {
                    rename($key, $key . '.held');
                    if ($kind !== 'missing') { file_put_contents($key, $kind === 'corrupt' ? 'invalid' : ''); }
                }
                $child = new WorkerProcess($directory, $kind, [PHP_BINARY, 'bin/worker.php'],
                    ['TABLO_DB' => $directory->path . '/db.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
                if ($live) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($directory->path . '/entered') && $child->running() && microtime(true) < $deadline) {
                        clearstatcache(); usleep(20_000);
                    }
                    Assert::true(is_file($directory->path . '/entered'), 'real health request reached barrier');
                    // A separate real PDO writer remains possible while network work is blocked.
                    $other = Database::connect($directory->path . '/db.sqlite');
                    $other->exec('UPDATE installation_settings SET check_interval_minutes = 2');
                    unset($other);
                    rename($key, $key . '.held');
                    if ($kind === 'live-replacement') { file_put_contents($key, random_bytes(32)); }
                    file_put_contents($directory->path . '/release', data: 'ready');
                }
                $result = $child->wait();
                WorkerKeyOutcomeAssertions::refused($result, WorkerKeyObservation::read($directory, $db), $kind, self::FAILURE);
                Assert::same(hash_file('sha256', $key . '.held'), $hash);
                WorkerKeyAssertions::unchanged($kind, $key, \Tablo\Tests\Support\FixtureValue::string($hash));
                $lock = new WorkerLock();
                Assert::true($lock->acquire($db)); $lock->close();
            } finally { $child?->close(); $server?->close(); unset($other, $lock, $child, $server, $db); $directory->close(); }
        }
    }
}
