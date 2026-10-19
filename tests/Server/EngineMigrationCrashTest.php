<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\RequiredFile;
use Tablo\ServerLock;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineProcess;
use Tablo\Tests\Support\EngineSchedule;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

/** Real nonzero client exits; actual owned server crash/restart is a separately required sublane. */
final class EngineMigrationCrashTest
{
    #[Test]
    public function clientExitBeforeLockLeavesNoReceiptOrDdlAndFreshInitializationSucceeds(): void
    { self::schedule('exit-before-lock', 0, phase: 'initializing'); }

    #[Test]
    public function clientExitAfterNativeLockReleasesItWithoutCreatingReceiptOrDdl(): void
    { self::schedule('exit-after-lock', 0, phase: 'initializing'); }

    #[Test]
    public function clientExitAfterDdlLeavesIntentAndPhysicalTableForRecognizedResume(): void
    { self::schedule('exit-after-ddl', 4, phase: 'initializing'); }

    #[Test]
    public function clientExitBeforePublicationLeavesCompleteDdlNonReadyUntilFreshResume(): void
    { self::schedule('exit-before-publication', 18, phase: 'initializing'); }

    #[Test]
    public function clientExitAfterPublicationPreservesReadyIdentityAndResumeIsANoop(): void
    { self::schedule('exit-after-publication', 18, phase: 'ready'); }

    private static function schedule(string $operation, int $tables, string $phase): void
    {
        $fixture = new EngineFixture('migration-' . $operation);
        $directory = new TemporaryDirectory('tablo-migration-exit-'); $child = null; $primary = null;
        try {
            $deadline = hrtime(true) / 1_000_000_000 + 7;
            $child = EngineProcess::migration($fixture, $directory, $operation);
            $child->awaitOutput("ENGINE_MIGRATION_READY\n", min(2, self::remaining($deadline)));
            $child->sendBarrier("go\n");
            $child->awaitOutput("ENGINE_MIGRATION_EXIT_BOUNDARY\n", self::remaining($deadline));
            $observer = $fixture->maintenance();
            $probe = new ServerLock();
            $free = $probe->acquire($observer, 'migration');
            Assert::same($free, $operation === 'exit-before-lock');
            $probe->close();
            self::durableState($fixture, $observer, $tables, $phase);
            $before = self::snapshot($observer, $tables);
            $receipt = EngineSchedule::receipt($fixture->case);
            $digest = $tables === 0 ? null : RequiredFile::digest($receipt);
            Assert::same(file_exists($receipt), $tables !== 0);
            $child->sendBarrier("exit\n");
            $result = $child->finish(self::remaining($deadline));
            Assert::same([$result['exit_code'], $result['stderr'], $result['stdout']],
                [86, '', "ENGINE_MIGRATION_READY\nENGINE_MIGRATION_EXIT_BOUNDARY\n"]);
            Assert::same(self::snapshot($observer, $tables), $before);
            $resume = $fixture->maintenance();
            Assert::true(self::releasedLock($resume, $deadline));
            (new ServerMigration($resume, $fixture->vault))->initialize($receipt);
            if ($digest !== null) { Assert::same(RequiredFile::digest($receipt), $digest); }
            self::durableState($fixture, $resume, 18, phase: 'ready');
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $resume), 18);
            if ($phase === 'ready') { Assert::same(self::snapshot($resume, 18), $before); }
        } catch (\Throwable $error) {
            $primary = $error; $directory->retainFailure($error); throw $error;
        } finally {
            if ($child !== null) { $directory->cleanup(static fn () => $child->close($primary)); }
            $directory->cleanup($fixture->close(...)); $directory->cleanup($directory->close(...));
        }
    }

    private static function durableState(EngineFixture $fixture, \PDO $db, int $tables, string $phase): void
    {
        Assert::same(count(ServerSchema::objects($db)), $tables);
        if ($tables === 0) { return; }
        Assert::same((new ServerMigration($db, $fixture->vault))->status(),
            ['format' => 1, 'logical_version' => $phase === 'ready' ? 10 : 0, 'phase' => $phase]);
        if ($tables === 4) {
            Assert::same(ServerSchema::rows($db, 'SELECT phase, failure_category FROM tablo_migration_journal WHERE step = 3'),
                [['phase' => 'intent', 'failure_category' => null]]);
        }
        foreach (['writer', 'reader'] as $role) {
            if ($phase === 'ready') { Assert::same($fixture->scalar('SELECT 1', $fixture->independent($role)), 1); continue; }
            $failure = null;
            try { $fixture->independent($role); } catch (DatabaseFailure $error) { $failure = $error; }
            Assert::same($failure?->category, 'schema'); Assert::same($failure?->getPrevious(), null);
        }
    }

    /** @return array */
    private static function snapshot(\PDO $db, int $tables): array
    {
        if ($tables === 0) { return ['objects' => ServerSchema::objects($db)]; }
        return ['objects' => ServerSchema::objects($db), 'state' => ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'),
            'journal' => ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY plan, step')];
    }

    private static function remaining(float $deadline): float
    {
        $seconds = $deadline - hrtime(true) / 1_000_000_000;
        if ($seconds <= 0) { throw new \RuntimeException('Migration client exit exceeded its fixed case budget.'); }
        return min(6, $seconds);
    }

    /** Process exit and server TCP cleanup are separate observations, with one fixed subdeadline. */
    private static function releasedLock(\PDO $db, float $deadline): bool
    {
        $until = min($deadline, hrtime(true) / 1_000_000_000 + 1); $lock = new ServerLock();
        do {
            if ($lock->acquire($db, 'migration')) {
                try { $lock->assertOwned(); return true; } finally { $lock->close(); }
            }
            usleep(1_000);
        } while (hrtime(true) / 1_000_000_000 < $until);
        return false;
    }
}
