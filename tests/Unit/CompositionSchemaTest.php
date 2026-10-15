<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SchemaShape;
use Testo\Assert;
use Testo\Test;

final class CompositionSchemaTest extends CompositionSchemaFixtureCase
{



    #[Test]
    public function independentlyMaterializedLineagesPreserveRowsAndProspectiveActivation(): void
    {
        foreach (['common0', 'common1', 'common2', 'common3', 'common4', 'endpoint5', 'history5', 'incident6', 'notification7'] as $name) {
            $db = self::fixture($name);
            if ($name === 'endpoint5') { $db->exec("UPDATE sites SET endpoint_token='original-cipher', enabled=0"); }
            $before = self::snapshot($db);
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 8, $name);
            foreach ($before as $table => $rows) {
                if (in_array($table, ['schema', 'version', 'sqlite_sequence'], strict: true)) { continue; }
                $actual = SchemaShape::rows($db, 'SELECT * FROM "' . $table . '" ORDER BY rowid');
                foreach ($rows as $index => $row) { Assert::same(array_intersect_key($actual[$index], $row), $row, $name . ':' . $table); }
            }
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM incidents')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM notification_slots')->fetchColumn(), 0);
            $db->statements = [];
            $after = self::snapshot($db);
            Database::migrate($db);
            Assert::same(self::snapshot($db), $after);
            Assert::same(array_values(array_filter($db->statements,
                static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT)/', $sql) === 1)), []);
            Assert::same((int) $db->query('PRAGMA foreign_keys')->fetchColumn(), 1);
        }
    }
}
