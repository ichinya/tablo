<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

/**
 * Physical metadata and parsed constraints for the supported installation lineages.
 * @phpstan-type SchemaObject array{name: string, type: string, tbl_name: string, sql: ?string}
 * @phpstan-type TableColumn array{cid: int, name: string, type: string, notnull: int, dflt_value: ?string, pk: int}
 * @phpstan-type ExtendedColumn array{cid: int, name: string, type: string, notnull: int, dflt_value: ?string, pk: int, hidden: int}
 * @phpstan-type IndexInfo array{seq: int, name: string, unique: int, origin: string, partial: int}
 * @phpstan-type IndexColumn array{seqno: int, cid: int, name: ?string, desc: int, coll: ?string, key: int}
 * @phpstan-type ForeignKey array{id: int, seq: int, table: string, from: string, to: ?string, on_update: string, on_delete: string, match: string}
 */
final class SchemaShape
{
    /** @return list<array> */
    public static function rows(PDO $db, string $sql): array
    {
        $statement = \Tablo\SqlStatement::checked($db->query($sql));
        try { return SqlRows::all($statement->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): array => $row); }
        finally { $statement->closeCursor(); }
    }

    /** @return array<string, SchemaObject> */
    public static function objects(PDO $db): array
    {
        $objects = [];
        // SQLite's schema-table projection, fetched as associative rows on this exact connection.
        /** @var list<SchemaObject> $rows */
        $rows = self::rows($db, 'SELECT name, type, tbl_name, sql FROM main.sqlite_schema');
        foreach ($rows as $row) {
            $objects[strtolower($row['name'])] = $row;
        }
        return $objects;
    }

    public static function assert(PDO $db, PDO $expected, int $version = Database::CURRENT_SCHEMA_VERSION): void
    {
        // COLD_NESTED_BEGIN 400
        RuntimeIni::$coldNestedProbe?->__invoke(400, memory_get_peak_usage(false));
        // COLD_NESTED_END 400
        $actualObjects = self::objects($db);
        // COLD_NESTED_BEGIN 401
        RuntimeIni::$coldNestedProbe?->__invoke(401, memory_get_peak_usage(false));
        // COLD_NESTED_END 401
        foreach (self::objects($expected) as $name => $object) {
            if ($object['type'] !== 'table' || $name === 'sqlite_sequence') { continue; }
            // COLD_NESTED_BEGIN 402
            RuntimeIni::$coldNestedProbe?->__invoke(402, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 402
            $actual = $actualObjects[$name] ?? null;
            if ($actual === null && $version === 0) { continue; }
            if ($actual === null || $actual['type'] !== 'table') { self::refuse(); }
            if ($actual['sql'] === null || $object['sql'] === null) { self::refuse(); }
            $columns = SchemaColumnShape::columns($db, $name);
            // COLD_NESTED_BEGIN 403
            RuntimeIni::$coldNestedProbe?->__invoke(403, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 403
            $wanted = SchemaColumnShape::columns($expected, $name);
            // COLD_NESTED_BEGIN 404
            RuntimeIni::$coldNestedProbe?->__invoke(404, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 404
            $missing = SchemaColumnShape::validate($columns, $wanted, $version, $name);
            // COLD_NESTED_BEGIN 405
            RuntimeIni::$coldNestedProbe?->__invoke(405, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 405
            SchemaCheckShape::validate($actual['sql'], $object['sql'], $version, $missing);
            // COLD_NESTED_BEGIN 406
            RuntimeIni::$coldNestedProbe?->__invoke(406, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 406
            SchemaForeignShape::validate(SchemaForeignShape::foreignKeys($db, $name), SchemaForeignShape::foreignKeys($expected, $name), $version, $missing);
            // COLD_NESTED_BEGIN 407
            RuntimeIni::$coldNestedProbe?->__invoke(407, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 407
            SchemaIndexShape::validate(SchemaIndexShape::indexes($db, $name), SchemaIndexShape::indexes($expected, $name));
            // COLD_NESTED_BEGIN 408
            RuntimeIni::$coldNestedProbe?->__invoke(408, memory_get_peak_usage(false), $name);
            // COLD_NESTED_END 408

        }
    }











    public static function refuse(): never
    {
        throw new RuntimeException('Incompatible SQLite schema layout.');
    }
}
