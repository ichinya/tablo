<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SchemaReference;
use Tablo\SettingsRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class SessionSettingsMigrationTest extends CompositionSchemaFixtureCase
{
    private static function versionEight(): MigrationPdo
    {
        $reference = SchemaReference::reference(8, ['endpoint' => true]);
        $db = new MigrationPdo(':memory:');
        foreach (\Tablo\SchemaShape::objects($reference) as $name => $object) {
            if (str_starts_with($name, 'sqlite_')) { continue; }
            $db->exec($object['sql'] ?? throw new \RuntimeException('Fixture object requires SQL.'));
        }
        $db->exec("PRAGMA user_version=8; INSERT INTO installation_settings(id,check_interval_minutes) VALUES(1,17);
            INSERT INTO users(id,password_hash) VALUES(1,'retained-admin');
            INSERT INTO sites(id,name,url,repository,endpoint_token) VALUES(1,'Retained','https://example.test/','fixture/public','retained-cipher')");
        return $db;
    }

    #[Test]
    public function versionEightUpgradeOnlyAddsIdleColumnAndKeepsCurrentReadsWithoutDdl(): void
    {
        $db = self::versionEight();
        $before = self::snapshot($db);
        // Retain the original isolated 8->9 transition invariant, then roll it back.
        $db->exec('BEGIN IMMEDIATE'); $db->statements = [];
        \Tablo\SchemaLayoutUpgrade::addSessionSettings($db);
        $idleDdl = array_values(array_filter($db->statements, static fn(string $sql): bool => preg_match('/^(CREATE|ALTER|DROP)/', $sql) === 1));
        Assert::same(count($idleDdl), 1);
        Assert::true(str_starts_with(\Tablo\Tests\Support\FixtureValue::at($idleDdl, 0), 'ALTER TABLE installation_settings ADD COLUMN'));
        Assert::same((new SettingsRepository($db))->getIdleTimeout(), 30);
        $db->exec('ROLLBACK'); Assert::same(self::snapshot($db), $before);
        $db->statements = [];
        Database::migrate($db);
        $ddl = array_values(array_filter($db->statements, static fn(string $sql): bool => preg_match('/^(CREATE|ALTER|DROP)/', $sql) === 1));
        Assert::same(count($ddl), 3);
        Assert::true(str_starts_with(\Tablo\Tests\Support\FixtureValue::at($ddl, 0), 'ALTER TABLE installation_settings ADD COLUMN'));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($ddl, 1), \Tablo\ReadApiTokenSchema::sql());
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($ddl, 2), \Tablo\SelfUpdateSchema::sql());
        Assert::same(\Tablo\SchemaShape::rows($db, 'SELECT * FROM read_api_token'), []);
        Assert::same((int) SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
        $settings = new SettingsRepository($db);
        Assert::same($settings->get(), 17);
        Assert::same($settings->getIdleTimeout(), 30);
        foreach (['sites', 'users', 'check_history', 'incidents', 'notification_slots'] as $table) {
            Assert::same(\Tablo\SchemaShape::rows($db, 'SELECT * FROM ' . $table), \Tablo\Tests\Support\FixtureValue::at($before, $table));
        }
        $current = self::snapshot($db); $db->statements = [];
        Database::migrate($db);
        Assert::same(self::snapshot($db), $current);
        Assert::same(array_values(array_filter($db->statements, static fn(string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT)/', $sql) === 1)), []);
        $db->exec('PRAGMA user_version=' . (Database::CURRENT_SCHEMA_VERSION + 1)); $future = self::snapshot($db);
        $refused = false;
        try { Database::migrate($db); } catch (\RuntimeException) { $refused = true; }
        Assert::true($refused); Assert::same(self::snapshot($db), $future);
    }

    #[Test]
    public function failedAlterVersionAndCommitRollbackAndMissingSingletonStaysMissing(): void
    {
        foreach (['ALTER TABLE installation_settings ADD COLUMN', 'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION, 'COMMIT'] as $stage) {
            $db = self::versionEight(); $before = self::snapshot($db); $db->failBefore = $stage;
            $message = null;
            try { Database::migrate($db); } catch (\PDOException $error) { $message = $error->getMessage(); }
            Assert::true(is_string($message) && str_contains($message, 'injected_migration_failure'));
            Assert::same(self::snapshot($db), $before);
            Assert::true(in_array('ROLLBACK', $db->statements, strict: true));
        }
        $db = self::versionEight(); $db->exec('DELETE FROM installation_settings'); Database::migrate($db);
        Assert::same((new SettingsRepository($db))->getIdleTimeout(), 30);
        Assert::same((int) SqlStatement::checked($db->query('SELECT count(*) FROM installation_settings'))->fetchColumn(), 0);
    }
}
