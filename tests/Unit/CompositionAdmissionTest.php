<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class CompositionAdmissionTest extends CompositionSchemaFixtureCase
{


    #[Test]
    public function ambiguousCorruptFutureAndUnreviewedDependenciesRefuseBeforeBegin(): void
    {
        $cases = [
            ['common4', 'PRAGMA user_version=5'],
            ['endpoint5', 'CREATE TABLE check_history(fake INTEGER)'],
            ['history5', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
            ['history5', 'DROP INDEX check_history_time'],
            ['history5', 'DROP TABLE check_history; CREATE VIEW check_history AS SELECT 1 AS id'],
            ['endpoint5', 'ALTER TABLE sites DROP COLUMN endpoint_token; ALTER TABLE sites ADD COLUMN endpoint_token INTEGER'],
            ['history5', 'PRAGMA user_version=4'],
            ['incident6', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
            ['notification7', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
            ['notification7', 'PRAGMA user_version=9'],
            ['history5', 'CREATE TABLE _tablo_history_upgrade(fake INTEGER)'],
            ['history5', 'CREATE TABLE custom_child(id INTEGER REFERENCES check_history(id))'],
            ['history5', 'CREATE INDEX custom_history ON check_history(online)'],
            ['history5', 'CREATE TRIGGER custom_history AFTER INSERT ON check_history BEGIN SELECT 1; END'],
        ];
        foreach ($cases as [$name, $sql]) {
            $db = self::fixture($name);
            $db->exec($sql);
            $before = self::snapshot($db);
            $db->statements = [];
            Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class, $name . ':' . $sql);
            Assert::false(in_array('BEGIN IMMEDIATE', $db->statements, strict: true));
            Assert::same(self::snapshot($db), $before);
        }
        foreach ([
            ["CHECK (health_error_code IN (", "CHECK (1) /* health_error_code IN ("],
            ['REFERENCES sites(id) ON DELETE CASCADE', 'REFERENCES sites(id) ON DELETE RESTRICT'],
            ['CREATE INDEX check_history_time ON check_history(checked_at, id)', 'CREATE INDEX check_history_time ON check_history(id, checked_at)'],
        ] as [$from, $to]) {
            $sql = file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/history5.sql');
            switch (true) {
case (str_contains($to, '/*')):

                $sql = preg_replace('/CHECK \(health_error_code IN \([\s\S]*?\)\)/', replacement: 'CHECK (1) /* original CHECK omitted */', subject: $sql);

    break;
default:
 $sql = str_replace($from, $to, $sql);
    break;
}
            $db = new MigrationPdo(':memory:'); $db->exec($sql); $before = self::snapshot($db);
            Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
            Assert::same(self::snapshot($db), $before);
        }
        $db = Database::connect(':memory:'); $db->exec('DROP INDEX notification_due'); $before = self::snapshot($db);
        Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
        Assert::same(self::snapshot($db), $before);
    }
}
