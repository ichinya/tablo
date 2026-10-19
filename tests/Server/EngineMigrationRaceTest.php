<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\ServerLock;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

/** Real independent sessions and native lock loss; simultaneous migration child processes remain a separate qualification. */
final class EngineMigrationRaceTest
{
    #[Test]
    public function competingMaintenanceSessionCannotWriteAReceiptOrDdlWhileTheLockIsHeld(): void
    {
        $fixture = new EngineFixture('migration-lock-contender');
        $owner = $fixture->maintenance(); $contender = $fixture->maintenance(); $lock = new ServerLock();
        Assert::true($lock->acquire($owner, 'migration'));
        Assert::true($fixture->scalar('SELECT CONNECTION_ID()', $owner) !== $fixture->scalar('SELECT CONNECTION_ID()', $contender));
        $path = EngineSchedule::receipt($fixture->case); Assert::false(file_exists($path));
        $caught = null;
        try {
            try { (new ServerMigration($contender, $fixture->vault))->initialize($path); }
            catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'busy'); Assert::same($caught?->getPrevious(), null);
            Assert::false(file_exists($path)); Assert::same(ServerSchema::objects($contender), []);
            $lock->assertOwned();
        } finally { $lock->close(); $fixture->close(); }
    }

    #[Test]
    public function lostNativeMaintenanceLockStopsBeforeTheNextDdlAndExactResumeStillWorks(): void
    {
        $fixture = new EngineFixture('migration-lock-lost');
        $db = $fixture->maintenance();
        $migration = new ServerMigration($db, $fixture->vault, static function (string $phase, int $step) use ($db, $fixture): void {
            if ([$phase, $step] === ['after-journal', 2]) { Assert::same($fixture->scalar('SELECT RELEASE_ALL_LOCKS()', $db), 1); }
        });
        $caught = null;
        try { $migration->initialize(EngineSchedule::receipt($fixture->case)); }
        catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'busy'); Assert::same($caught?->getPrevious(), null);
        Assert::same(ServerSchema::objects($db), ['tablo_database_state', 'tablo_migration_journal', 'tablo_writer_guard']);
        Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $db), 3);
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 0, 'phase' => 'initializing']);
        (new ServerMigration($fixture->maintenance(), $fixture->vault))->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        $fixture->close();
    }
}
