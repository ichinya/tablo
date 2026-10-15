<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;

abstract class SqlSchemaFixtureCase extends CompositionSchemaFixtureCase
{
    protected static function database(string $lineage): MigrationPdo
    {
        if ($lineage !== 'current8') { return self::fixture($lineage); }
        $db = new MigrationPdo(':memory:');
        Database::migrate($db);
        return $db;
    }

    protected static function redeclare(MigrationPdo $db, string $declaration, ?callable $transform = null): void
    {
        $sql = $db->query("SELECT sql FROM sqlite_schema WHERE name='check_history'")->fetchColumn();
        $sql = str_replace('id INTEGER PRIMARY KEY AUTOINCREMENT', $declaration, $sql, $count);
        Assert::same($count, 1);
        if ($transform !== null) { $sql = $transform($sql); }
        $db->exec('DROP TABLE check_history');
        $db->exec($sql);
        $db->exec('CREATE INDEX check_history_site_time ON check_history(site_id,checked_at,id)');
        $db->exec('CREATE INDEX check_history_time ON check_history(checked_at,id)');
    }

    protected static function quotedChecks(string $sql): string
    {
        $sql = str_replace('CHECK(', replace: 'CONSTRAINT "check" CHECK(', subject: $sql);
        return str_replace('version_status IN', replace: '"version_status" iN', subject: $sql);
    }

    protected static function insertHistory(MigrationPdo $db): int
    {
        $db->exec("INSERT OR IGNORE INTO sites(id,name,url,repository) VALUES(1,'SQL admission','https://example.test/','fixture/public')");
        $db->exec("INSERT INTO check_history(site_id,config_revision,checked_at,version_status)
            VALUES(1,0,'2026-01-01T00:00:00Z','skipped')");
        return (int) $db->lastInsertId();
    }
}
