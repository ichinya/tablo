<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseSession;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\SqlColumn;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

final class EngineMigrationFreshTest
{
    #[Test]
    public function initializesExactlyEighteenTablesAndKeepsApiOff(): void
    {
        $fixture = new EngineFixture('migration-fresh');
        $db = $fixture->maintenance();
        Assert::same(ServerSchema::objects($db), []);
        $migration = new ServerMigration($db, $fixture->vault);
        $migration->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same($migration->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        Assert::same(self::count($db, 'SELECT COUNT(*) AS value FROM read_api_token'), 0);
        Assert::same(self::count($db, 'SELECT COUNT(*) AS value FROM tablo_migration_journal WHERE phase = \'done\''), 18);
        Assert::same(self::count($db, 'SELECT COUNT(*) AS value FROM users'), 0);
        Assert::same(DatabaseSession::get($db)?->configuration->engine, getenv('TABLO_TEST_DB_DRIVER'));
        $fixture->close();
    }

    #[Test]
    public function completedBootstrapRerunPreservesIdentityAndRows(): void
    {
        $fixture = new EngineFixture('migration-rerun');
        $db = $fixture->maintenance();
        $migration = new ServerMigration($db, $fixture->vault);
        $migration->initialize(EngineSchedule::receipt($fixture->case));
        $before = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
        $migration->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $before);
        Assert::same(self::count($db, 'SELECT COUNT(*) AS value FROM notification_settings'), 1);
        Assert::same(self::count($db, 'SELECT COUNT(*) AS value FROM tablo_migration_journal'), 18);
        $fixture->close();
    }

    private static function count(\PDO $db, string $sql): int
    {
        return SqlColumn::integer(ServerSchema::rows($db, $sql)[0] ?? [], 'value');
    }
}
