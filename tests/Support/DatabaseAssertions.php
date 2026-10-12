<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Throwable;



/**
 * @phpstan-type SchemaObject array{name: string, type: string, sql: ?string, ...}
 * @phpstan-type Snapshot array{version: int, schema: list<SchemaObject>, rows: array<string, list<array>>}
 */
final class DatabaseAssertions
{
    public static function schema(SchemaFixture $fixture = SchemaFixture::Current): string
    {
        return \Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . ($fixture === SchemaFixture::Historic ? '/tests/fixtures/pre-json-schema.sql' : '/database/schema.sql')));
    }

    /** @return Snapshot */
    public static function snapshot(PDO $db): array
    {
        $schema = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT name, type, sql FROM sqlite_schema ORDER BY name'))->fetchAll(PDO::FETCH_ASSOC), self::schemaObject(...));
        $rows = [];
        foreach ($schema as $object) {
            if (!($object['type'] === 'table')) { continue; }

                // Table payloads intentionally remain raw, including historical and corrupt values.
                $rows[$object['name']] = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM "' . $object['name'] . '" ORDER BY 1'))->fetchAll(PDO::FETCH_ASSOC), \Tablo\JsonRecord::checked(...));

        }
        return ['version' => (int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 'schema' => $schema, 'rows' => $rows];
    }

    /** @return SchemaObject */
    private static function schemaObject(array $row): array
    {
        $row['name'] = \Tablo\SqlColumn::string($row, 'name');
        $row['type'] = \Tablo\SqlColumn::string($row, 'type');
        $row['sql'] = \Tablo\SqlColumn::nullableString($row, 'sql');
        return $row;
    }

    public static function errorMessage(callable $action): ?string
    {
        try { $action(); } catch (Throwable $error) { return $error->getMessage(); }
        return null;
    }

}
