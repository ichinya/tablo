<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Throwable;



final class DatabaseAssertions
{
    public static function schema(SchemaFixture $fixture = SchemaFixture::Current): string
    {
        return file_get_contents(dirname(__DIR__, levels: 2) . ($fixture === SchemaFixture::Historic ? '/tests/fixtures/pre-json-schema.sql' : '/database/schema.sql'));
    }

    public static function snapshot(PDO $db): array
    {
        $schema = $db->query('SELECT name, type, sql FROM sqlite_schema ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($schema as $object) {
            if (!($object['type'] === 'table')) { continue; }

                $rows[$object['name']] = $db->query('SELECT * FROM "' . $object['name'] . '" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);

        }
        return ['version' => (int) $db->query('PRAGMA user_version')->fetchColumn(), 'schema' => $schema, 'rows' => $rows];
    }

    public static function errorMessage(callable $action): ?string
    {
        try { $action(); } catch (Throwable $error) { return $error->getMessage(); }
        return null;
    }

}
