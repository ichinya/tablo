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
            // Keep the original site-settlement failure boundary independent of the new pre-pass claim.
            (new \Tablo\SelfUpdateRepository($db))->configure(null, '360');
            $child = new WorkerProcess($directory, 'readonly', [PHP_BINARY, '-d',
                'auto_prepend_file=' . dirname(__DIR__) . '/fixtures/WorkerReadonlyControl.php', 'bin/worker.php'],
                ['TABLO_DB' => $directory->path . '/db.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            $result = $child->wait();
            Assert::same($result['exit_code'], 1);
            Assert::same($result['stderr'], self::FAILURE);
            Assert::false(str_contains($result['stdout'], 'Worker pass complete.'));
            Assert::same(self::requests($directory), ['/up'], 'one actual health request; no next site/pass');
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL'))->fetchColumn(), 0);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM worker_progress'))->fetchColumn(), 0);
            $lock = new WorkerLock();
            Assert::true($lock->acquire($db), 'OS successor acquisition after exit1 despite failed finish');
            $lock->close();
        } finally { $child?->close(); $server?->close(); unset($lock, $child, $server, $db); $directory->close(); }
    }



    #[Test]
    public function realCliReadonlySelfUpdateClaimFailsBeforeHealthAndPreservesSuccessDespiteFailedFinish(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-readonly-update-');
        $child = null; $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            self::sites($db, $directory, $server->base);
            $build = self::seedDueUpdate($db); $before = (new \Tablo\SelfUpdateRepository($db))->get();
            $child = new WorkerProcess($directory, 'readonly-update', [PHP_BINARY, '-d',
                'auto_prepend_file=' . dirname(__DIR__) . '/fixtures/WorkerReadonlyControl.php', 'bin/worker.php'],
                ['TABLO_DB' => $directory->path . '/db.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                    'TABLO_BUILD_REPOSITORY' => $build->repository, 'TABLO_BUILD_BRANCH' => $build->branch, 'TABLO_BUILD_SHA' => $build->sha]);
            $result = $child->wait();
            Assert::true(str_contains($result['stdout'], 'Worker started.'), 'real generation startup reaches the native query-only control');
            Assert::same($result['exit_code'], 1); Assert::same($result['stderr'], self::FAILURE);
            Assert::false(str_contains($result['stdout'], 'Worker pass complete.'));
            Assert::same(self::requests($directory), [], 'enabled due self-update claim refuses before any health request');
            Assert::same((new \Tablo\SelfUpdateRepository($db))->get(), $before, 'failed claim cannot change saved success, retry budget or ownership');
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL'))->fetchColumn(), 0);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM worker_progress'))->fetchColumn(), 0);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 0);
            Assert::true((new \Tablo\WorkerStateRepository($db))->generation() !== null, 'read-only finalization cannot clear the actual started generation');
            $lock = new WorkerLock(); Assert::true($lock->acquire($db), 'OS successor acquires after self-update claim and finish both fail'); $lock->close();
        } finally { $child?->close(); $server?->close(); unset($lock, $child, $server, $db); $directory->close(); }
    }

    private static function seedDueUpdate(\PDO $db): \Tablo\BuildMetadata
    {
        $build = new \Tablo\BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $updates = new \Tablo\SelfUpdateRepository($db);
        $claim = $updates->claim($build, 1000) ?? throw new \RuntimeException('Due self-update fixture claim required.');
        Assert::true($updates->complete($claim, $build, 1000, new \Tablo\SelfUpdateResult('behind', str_repeat('b', times: 40), 2)));
        return $build;
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
