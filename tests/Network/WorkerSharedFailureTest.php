<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\PeriodicWorker;
use Tablo\SharedKeyFailure;
use Tablo\WorkerLock;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerHttp;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class WorkerSharedFailureTest extends WorkerFailureFixtureCase
{


    #[Test]
    public function realCliReadonlyAfterStartupRefusesNextSiteAndReleasesLockEvenWhenFinishFails(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-readonly-r1-');
        $child = null; $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            self::sites($db, $directory, $server->base);
            $child = new WorkerProcess($directory, 'readonly', [PHP_BINARY, '-d',
                'auto_prepend_file=' . dirname(__DIR__) . '/fixtures/WorkerReadonlyControl.php', 'bin/worker.php'],
                ['TABLO_DB' => $directory->path . '/db.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            $result = $child->wait();
            Assert::same($result['exit_code'], 1);
            Assert::same($result['stderr'], self::FAILURE);
            Assert::false(str_contains($result['stdout'], 'Worker pass complete.'));
            Assert::same(self::requests($directory), ['/up'], 'one actual health request; no next site/pass');
            Assert::same((int) $db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
            $lock = new WorkerLock();
            Assert::true($lock->acquire($db), 'OS successor acquisition after exit1 despite failed finish');
            $lock->close();
        } finally { $child?->close(); $server?->close(); unset($lock, $child, $server, $db); $directory->close(); }
    }



    #[Test]
    public function originalKeyFailureSurvivesReadonlyFinalizationAndNextPassRefusesRemovedKey(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-finalization-r1-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            self::sites($db, $directory, $server->base);
            $key = $directory->path . '/github-token.key';
            $worker = new PeriodicWorker($db, new WorkerHttp($server->base), hooks: new \Tablo\WorkerHooks(output: static function (string $message) use ($db, $key): void {
                if ($message === 'Worker started.') { $db->exec('PRAGMA query_only = ON'); rename($key, $key . '.held'); }
            }));
            $error = null;
            try { $worker->run(); } catch (\Throwable $caught) { $error = $caught; }
            Assert::instanceOf($error, SharedKeyFailure::class, 'cleanup PDO error must not replace original fatal semantics');
            Assert::same($error->getPrevious(), null);
            Assert::same(self::requests($directory), []);
            $lock = new WorkerLock(); Assert::true($lock->acquire($db)); $lock->close();
            $db->exec('PRAGMA query_only = OFF');
            rename($key . '.held', $key);
            $worker = new PeriodicWorker($db, new WorkerHttp($server->base), hooks: new \Tablo\WorkerHooks(output: static function (): void {}));
            $worker->runPass();
            $before = self::requests($directory);
            Assert::same(count($before), 2, 'individual malformed credentials still continue with valid shared key');
            rename($key, $key . '.held');
            try { $worker->runPass(); Assert::true(false); } catch (SharedKeyFailure $discardedCleanup) { unset($discardedCleanup); }
            Assert::same(self::requests($directory), $before, 'next pass refuses before another request');
        } finally { unset($caught, $error, $lock, $worker, $db); gc_collect_cycles(); $server?->close(); $directory->close(); }
    }
}
