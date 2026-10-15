<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\ReadApiTokenSchema;
use Tablo\SchemaReference;
use Tablo\SchemaShape;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class ReadApiTokenMigrationTest
{
    private static function versionNine(): MigrationPdo
    {
        $reference = SchemaReference::reference(9, ['endpoint' => true]); $db = new MigrationPdo(':memory:');
        foreach (SchemaShape::objects($reference) as $name => $object) {
            if (str_starts_with($name, 'sqlite_')) { continue; }
            $db->exec($object['sql'] ?? throw new \RuntimeException('SQL required.'));
        }
        $db->exec('PRAGMA user_version=9'); A::administrator($db); A::insert($db);
        return $db;
    }

    #[Test]
    public function schemaNineToTenAddsExactlyEmptyConstrainedTokenTableAndCurrentMigrationHasNoEffects(): void
    {
        $db = self::versionNine(); $before = A::snapshot($db); $db->statements = [];
        Database::migrate($db);
        $ddl = array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(CREATE|ALTER|DROP)/', $sql) === 1));
        Assert::same($ddl, [ReadApiTokenSchema::sql()]);
        Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), []);
        $after = A::snapshot($db);
        foreach (array_keys($before) as $key) {
            if (in_array($key, ['schema','version'], strict: true)) { continue; }
            Assert::same($after[$key] ?? null, $before[$key] ?? null);
        }
        $db->statements = []; Database::migrate($db); Assert::same(A::snapshot($db), $after);
        Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT)/', $sql) === 1)), []);
        $db = null;
    }

    #[Test]
    public function tableVersionAndCommitFaultsAreReachedAndRestoreTheEntireOriginalSchemaAndRows(): void
    {
        foreach (['CREATE TABLE read_api_token', 'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION, 'COMMIT'] as $stage) {
            $db = self::versionNine(); $before = A::snapshot($db); $db->statements = []; $db->failBefore = $stage;
            $message = null;
            try { Database::migrate($db); } catch (\PDOException $error) { $message = $error->getMessage(); }
            Assert::true(is_string($message) && str_contains($message, 'injected_migration_failure'));
            Assert::true(in_array('ROLLBACK', $db->statements, strict: true)); Assert::same(A::snapshot($db), $before); $db = null;
        }
    }

    #[Test]
    public function currentMissingOrCorruptTokenShapeAndFutureSchemaRefuseWithoutMutation(): void
    {
        foreach (['DROP TABLE read_api_token', 'ALTER TABLE read_api_token RENAME COLUMN secret_hash TO damaged',
            'PRAGMA user_version=' . (Database::CURRENT_SCHEMA_VERSION + 1)] as $change) {
            $db = A::database(); $db->exec($change); $before = A::snapshot($db);
            A::reject(static fn () => Database::migrate($db)); Assert::same(A::snapshot($db), $before); $db = null;
        }
    }
}
