<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

/** Exception boundaries and real native DDL errors; process exit/server crash are separate mechanisms. */
final class EngineMigrationFailureTest
{
    #[Test]
    public function afterDdlFailureKeepsThePhysicalTableAndFailedJournalForRecognizedResume(): void
    {
        $fixture = new EngineFixture('migration-failure-ddl');
        self::interruptedStep($fixture, 'after-ddl'); $fixture->close();
    }

    #[Test]
    public function afterJournalFailureKeepsCommittedDdlAndCanResumeTheExactPlan(): void
    {
        $fixture = new EngineFixture('migration-failure-journal');
        self::interruptedStep($fixture, 'after-journal'); $fixture->close();
    }

    #[Test]
    public function beforePublicationFailureLeavesCompleteDdlExplicitlyNonReady(): void
    {
        $fixture = new EngineFixture('migration-failure-publication');
        $db = $fixture->maintenance();
        self::interrupt($fixture, 'before-publication', 10);
        Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 0, 'phase' => 'initializing']);
        self::notAdmitted($fixture);
        (new ServerMigration($db, $fixture->vault))->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        $fixture->close();
    }

    #[Test]
    public function afterPublicationFailureDoesNotUndoPublishedIdentityOrBootstrapTwice(): void
    {
        $fixture = new EngineFixture('migration-failure-published');
        $db = $fixture->maintenance();
        self::interrupt($fixture, 'after-publication', 10);
        $before = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        (new ServerMigration($db, $fixture->vault))->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $before);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM tablo_migration_journal', $db), 18);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_settings', $db), 1);
        $fixture->close();
    }

    /** Separate tests enter through this autoloaded existing physical owner. */
    public static function deniedDdlAt(int $step): void { engine_migration_denied_ddl($step); }
    public static function duplicateDdlAt(int $step): void { engine_migration_duplicate_ddl($step); }

    private static function interruptedStep(EngineFixture $fixture, string $phase): void
    {
        $db = $fixture->maintenance();
        Assert::same(ServerSchema::objects($db), []);
        self::interrupt($fixture, $phase, 3);
        $tables = ServerSchema::layout($db)['tables'];
        $expected = array_slice(array_column($tables, 'name'), offset: 0, length: 4); sort($expected, SORT_STRING);
        Assert::same(ServerSchema::objects($db), $expected);
        Assert::same(ServerSchema::rows($db, 'SELECT phase, failure_category FROM tablo_migration_journal WHERE step = 3'), [['phase' => 'failed', 'failure_category' => 'storage']]);
        self::notAdmitted($fixture);
        (new ServerMigration($db, $fixture->vault))->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $db), 18);
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
    }

    private static function interrupt(EngineFixture $fixture, string $phase, int $step): void
    {
        $failure = new \RuntimeException('synthetic durable-boundary interruption');
        $migration = new ServerMigration($fixture->maintenance(), $fixture->vault, static function (string $observed, int $index) use ($phase, $step, $failure): void {
            if ([$observed, $index] === [$phase, $step]) { throw $failure; }
        });
        $caught = null;
        try { $migration->initialize(EngineSchedule::receipt($fixture->case)); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught, $failure);
    }

    private static function notAdmitted(EngineFixture $fixture): void
    {
        $caught = null;
        try { $fixture->independent(); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    }
}

// Small native DDL fixture operations remain in this approved test owner.

function engine_migration_denied_ddl(int $step): void
{
    $fixture = new EngineFixture('migration-denied-ddl-' . $step);
    try {
        $db = $fixture->maintenance(); $sql = engine_migration_ddl_sql($db, $step);
        if ($step > 0) { engine_migration_ddl_prepare_prefix($fixture, $step - 1); }
        engine_migration_ddl_prefix($db, $step); $environment = $fixture->environment();
        $environment['TABLO_DB_MIGRATE_USER'] = \Tablo\SqlColumn::string($environment, 'TABLO_TEST_DB_DENIED_DDL_USER');
        $environment['TABLO_DB_MIGRATE_PASSWORD'] = \Tablo\SqlColumn::string($environment, 'TABLO_TEST_DB_DENIED_DDL_PASSWORD');
        $restricted = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment('migrator', $environment), mode: 'maintenance');
        if ($step > 0) { Assert::same($restricted->exec('UPDATE tablo_migration_journal SET phase = phase WHERE step = -1'), 0); }
        if ($step > 1) { Assert::same($restricted->exec('UPDATE tablo_database_state SET phase = phase WHERE id = 0'), 0); }
        $native = null;
        try { $restricted->exec($sql); } catch (\PDOException $error) { $native = \Tablo\DatabaseInteger::checked($error->errorInfo[1] ?? null); }
        Assert::same($native, 1142); engine_migration_ddl_prefix($db, $step);
        $points = []; $caught = null;
        $migration = new ServerMigration($restricted, $fixture->vault, static function (string $phase, int $index) use (&$points): void { $points[] = [$phase, $index]; });
        try { $migration->initialize(EngineSchedule::receipt($fixture->case)); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'unavailable'); Assert::same($caught?->getPrevious(), null);
        Assert::same(array_pop($points), ['before-ddl', $step]); engine_migration_ddl_prefix($db, $step);
        engine_migration_ddl_failed_step($db, $step, $step); engine_migration_ddl_not_ready($fixture); engine_migration_ddl_resume($fixture, $db);
    } finally { $fixture->close(); }
}

function engine_migration_duplicate_ddl(int $step): void
{
    $fixture = new EngineFixture('migration-duplicate-ddl-' . $step);
    try {
        $db = $fixture->maintenance(); $sql = engine_migration_ddl_sql($db, $step);
        $observed = new class {
            public ?int $native = null;
            /** @var array{string, int}|null */
            public ?array $point = null;
        };
        $migration = new ServerMigration($db, $fixture->vault, static function (string $phase, int $index) use ($step, $sql, $db, $observed): void {
            if ([$phase, $index] !== ['after-ddl', $step]) { return; }
            $observed->point = [$phase, $index];
            try { $db->exec($sql); } catch (\PDOException $error) {
                $observed->native = \Tablo\DatabaseInteger::checked($error->errorInfo[1] ?? null); throw $error;
            }
        });
        $caught = null;
        try { $migration->initialize(EngineSchedule::receipt($fixture->case)); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($observed->native, 1050); Assert::same($observed->point, ['after-ddl', $step]);
        Assert::same($caught?->category, 'unavailable'); Assert::same($caught?->getPrevious(), null);
        engine_migration_ddl_prefix($db, $step + 1); engine_migration_ddl_failed_step($db, $step, $step + 1);
        engine_migration_ddl_not_ready($fixture); engine_migration_ddl_resume($fixture, $db);
    } finally { $fixture->close(); }
}

function engine_migration_ddl_sql(\PDO $db, int $step): string
{
    $tables = ServerSchema::layout($db)['tables']; Assert::same(count($tables), 18);
    if ($step < 0 || $step > 17) { throw new \RuntimeException('DDL failure step outside the fixed bootstrap plan.'); }
    return ($tables[$step] ?? throw new \RuntimeException('DDL failure step absent.'))['create'];
}

function engine_migration_ddl_prepare_prefix(EngineFixture $fixture, int $step): void
{
    $primary = new \RuntimeException('synthetic committed-prefix setup'); $caught = null;
    $migration = new ServerMigration($fixture->maintenance(), $fixture->vault, static function (string $phase, int $index) use ($step, $primary): void {
        if ([$phase, $index] === ['after-journal', $step]) { throw $primary; }
    });
    try { $migration->initialize(EngineSchedule::receipt($fixture->case)); } catch (\RuntimeException $error) { $caught = $error; }
    Assert::same($caught, $primary);
}

function engine_migration_ddl_prefix(\PDO $db, int $count): void
{
    $expected = array_slice(array_column(ServerSchema::layout($db)['tables'], 'name'), offset: 0, length: $count); sort($expected, SORT_STRING);
    Assert::same(ServerSchema::objects($db), $expected);
}

function engine_migration_ddl_failed_step(\PDO $db, int $step, int $tables): void
{
    if ($tables === 0) { return; }
    $rows = ServerSchema::rows($db, 'SELECT phase,failure_category FROM tablo_migration_journal WHERE step = ?', [$step]);
    // Journal DDL is itself step0: a failure before its first intent cannot invent a durable row.
    Assert::same($rows, $step === 0 ? [] : [['phase' => 'failed', 'failure_category' => 'storage']]);
}

function engine_migration_ddl_resume(EngineFixture $fixture, \PDO $previous): void
{
    $receipt = EngineSchedule::receipt($fixture->case); $digest = \Tablo\RequiredFile::digest($receipt);
    $db = $fixture->maintenance(); $migration = new ServerMigration($db, $fixture->vault);
    Assert::true($fixture->scalar('SELECT CONNECTION_ID()', $db) !== $fixture->scalar('SELECT CONNECTION_ID()', $previous));
    $migration->initialize($receipt); Assert::same(\Tablo\RequiredFile::digest($receipt), $digest);
    Assert::same($migration->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
    Assert::same(count(ServerSchema::objects($db)), 18);
    Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $db), 18);
    Assert::same($fixture->scalar('SELECT COUNT(*) FROM users', $db), 0);
    foreach (['notification_settings', 'installation_settings', 'worker_runtime'] as $table) {
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM ' . $table, $db), 1);
    }
}

function engine_migration_ddl_not_ready(EngineFixture $fixture): void
{
    $caught = null;
    try { $fixture->independent(); } catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
}
