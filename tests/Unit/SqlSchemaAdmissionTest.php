<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use RuntimeException;
use Tablo\Database;
use Tablo\SchemaCheckShape;
use Tablo\SchemaColumnShape;
use Testo\Assert;
use Testo\Test;

final class SqlSchemaAdmissionTest extends SqlSchemaFixtureCase
{
    #[Test]
    public function refusesMissingAttributesAndKeywordNamesBeforeCurrentOrHistoricalEffects(): void
    {
        foreach (['current8', 'history5'] as $lineage) {
            foreach (['id INTEGER PRIMARY KEY', 'id INTEGER CONSTRAINT "autoincrement" PRIMARY KEY',
                "id INTEGER CONSTRAINT 'autoincrement' PRIMARY KEY", 'id INTEGER CONSTRAINT [AUTOINCREMENT] PRIMARY KEY',
                'id INTEGER CONSTRAINT `autoincrement` PRIMARY KEY', 'id INTEGER /* AUTOINCREMENT */ PRIMARY KEY',
                "id INTEGER -- AUTOINCREMENT\n PRIMARY KEY", 'id INTEGER CONSTRAINT "CHECK(autoincrement)" PRIMARY KEY'] as $declaration) {
                $db = self::database($lineage);
                self::redeclare($db, $declaration);
                $db->beginTransaction();
                $db->exec("INSERT OR IGNORE INTO users(id,password_hash) VALUES(1,'owned-caller-marker')");
                $before = self::snapshot($db);
                $db->statements = [];
                $error = self::error(static fn () => Database::migrate($db));
                Assert::instanceOf($error, RuntimeException::class, $declaration);
                Assert::same($error->getMessage(), 'Incompatible SQLite schema layout.');
                Assert::same(self::snapshot($db), $before);
                Assert::true($db->inTransaction());
                Assert::same(array_values(array_filter($db->statements,
                    static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
                $db->rollBack();
            }
        }
    }

    #[Test]
    public function genuineAttributesWithQuotedNamesAndChecksPreservePrunedHighWater(): void
    {
        foreach (['current8', 'history5'] as $lineage) {
            foreach (['id INTEGER CONSTRAINT "autoincrement" PRIMARY KEY AUTOINCREMENT',
                '"id" integer constraint [AUTOINCREMENT] primary /* attribute */ key autoincrement',
                '`id` INTEGER CONSTRAINT `autoincrement` PRIMARY KEY AUTOINCREMENT',
                "'id' INTEGER CONSTRAINT 'autoincrement' PRIMARY KEY AUTOINCREMENT"] as $declaration) {
                $db = self::database($lineage);
                self::redeclare($db, $declaration, self::quotedChecks(...));
                Assert::same(self::insertHistory($db), 1);
                $db->exec("UPDATE sqlite_sequence SET seq=900 WHERE name='check_history'");
                $db->exec('DELETE FROM check_history');
                Database::migrate($db);
                Assert::same(self::insertHistory($db), 901);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 8);
                Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC), []);
            }
        }
    }

    #[Test]
    public function quotedDefaultsAndCheckTermsRemainDistinctFromKeywords(): void
    {
        $wanted = new PDO('sqlite::memory:');
        $actual = new PDO('sqlite::memory:');
        $wanted->exec('CREATE TABLE probe(id INTEGER PRIMARY KEY, value TEXT DEFAULT NULL CHECK(value IS NULL))');
        $actual->exec('CREATE TABLE probe(id INTEGER PRIMARY KEY, value TEXT DEFAULT "null" CHECK(value IS "null"))');
        $wanted->exec('INSERT INTO probe DEFAULT VALUES');
        $actual->exec('INSERT INTO probe DEFAULT VALUES');
        Assert::same($wanted->query('SELECT value FROM probe')->fetchColumn(), null);
        Assert::same($actual->query('SELECT value FROM probe')->fetchColumn(), 'null');
        Assert::notSame(SchemaColumnShape::columns($actual, 'probe'), SchemaColumnShape::columns($wanted, 'probe'));
        $actualSql = $actual->query("SELECT sql FROM sqlite_schema WHERE name='probe'")->fetchColumn();
        $wantedSql = $wanted->query("SELECT sql FROM sqlite_schema WHERE name='probe'")->fetchColumn();
        Assert::instanceOf(self::error(static fn () => SchemaCheckShape::validate($actualSql, $wantedSql, version: 8, missing: [])), RuntimeException::class);
    }
}
