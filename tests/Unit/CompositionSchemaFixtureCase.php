<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\SchemaShape;
use Tablo\Tests\Support\MigrationPdo;

abstract class CompositionSchemaFixtureCase
{
    protected static function fixture(string $name): MigrationPdo
    {
        $db = new MigrationPdo(':memory:');
        $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/' . $name . '.sql')));
        $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'original-admin');
            INSERT INTO sites (id,name,url,repository) VALUES (1,'original-site','https://example.test/','fixture/public')");
        return $db;
    }

    /** @return array<string, list<array>> */
    protected static function snapshot(PDO $db): array
    {
        $result = ['schema' => SchemaShape::rows($db, 'SELECT type,name,tbl_name,sql FROM sqlite_schema ORDER BY name'),
            'version' => SchemaShape::rows($db, 'PRAGMA user_version')];
        foreach (SchemaShape::objects($db) as $name => $object) {
            if ($object['type'] !== 'table') { continue; }
            $result[$name] = SchemaShape::rows($db, 'SELECT * FROM "' . $name . '" ORDER BY rowid');
        }
        return $result;
    }

    protected static function error(callable $action): ?\Throwable
    {
        try { $action(); } catch (\Throwable $error) { return $error; }
        return null;
    }
}
