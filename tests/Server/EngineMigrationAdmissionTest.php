<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseConfiguration;
use Tablo\DatabaseConnectionFactory;
use Tablo\DatabaseFailure;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineMigrationAdmissionTest
{
    #[Test]
    public function futureLogicalVersionIsRefusedWithoutAutomaticMigration(): void
    {
        $fixture = new EngineFixture('admission-future');
        $fixture->maintenance()->exec('UPDATE tablo_database_state SET logical_version = 11 WHERE id = 1');
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function unknownPublishedLayoutChecksumIsRefusedWithoutRepair(): void
    {
        $fixture = new EngineFixture('admission-checksum');
        ServerSchema::rows($fixture->maintenance(), 'UPDATE tablo_database_state SET layout_checksum = ? WHERE id = 1', [str_repeat('0', times: 64)]);
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function inProgressCatalogCannotBeAdmittedAsAWriter(): void
    {
        $fixture = new EngineFixture('admission-phase');
        $fixture->maintenance()->exec("UPDATE tablo_database_state SET phase = 'migrating' WHERE id = 1");
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function missingHistoryIndexIsRefusedWithoutRecreation(): void
    {
        $fixture = new EngineFixture('admission-index');
        $fixture->maintenance()->exec('ALTER TABLE check_history DROP INDEX check_history_time');
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function missingForeignKeyIsRefusedWithoutAddingItBack(): void
    {
        $fixture = new EngineFixture('admission-foreign');
        $fixture->maintenance()->exec('ALTER TABLE check_history DROP FOREIGN KEY check_history_site_id_fk');
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function missingNativeCheckIsRefusedEvenWithReadyMetadata(): void
    {
        $fixture = new EngineFixture('admission-check');
        $sql = getenv('TABLO_TEST_DB_DRIVER') === 'mysql' ? 'ALTER TABLE sites DROP CHECK sites_c1' : 'ALTER TABLE sites DROP CONSTRAINT sites_c1';
        $fixture->maintenance()->exec($sql);
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function unrecognizedAdditionalObjectIsRefusedWithoutDroppingIt(): void
    {
        $fixture = new EngineFixture('admission-object');
        $maintenance = $fixture->maintenance();
        $maintenance->exec('CREATE TABLE unexpected_owned_table(id BIGINT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        self::refuses($fixture);
        Assert::same(count(ServerSchema::objects($maintenance)), 19);
        $fixture->close();
    }

    #[Test]
    public function wrongPhysicalColumnTypeCannotHideBehindThePublishedChecksum(): void
    {
        $fixture = new EngineFixture('admission-column');
        $fixture->maintenance()->exec("ALTER TABLE sites MODIFY sort_order VARBINARY(64) NOT NULL DEFAULT '0'");
        self::refuses($fixture); $fixture->close();
    }

    #[Test]
    public function normalExistingModeNeverInitializesAnEmptyOwnedCatalog(): void
    {
        $fixture = new EngineFixture('migration-admission-empty');
        $db = $fixture->maintenance(); Assert::same(ServerSchema::objects($db), []);
        $caught = null;
        try { DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment('migrator', $fixture->environment()), vault: $fixture->vault); }
        catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::same(ServerSchema::objects($db), []);
        $fixture->close();
    }

    private static function refuses(EngineFixture $fixture): void
    {
        $db = $fixture->maintenance();
        $objects = ServerSchema::objects($db); $before = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
        $caught = null;
        try { $fixture->independent(); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::same(ServerSchema::objects($db), $objects);
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $before);
    }
}
