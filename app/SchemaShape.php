<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

/** Physical metadata and parsed constraints for the supported installation lineages. */
final class SchemaShape
{
    public static function rows(PDO $db, string $sql): array
    {
        $statement = $db->query($sql);
        try { return $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
    }

    public static function objects(PDO $db): array
    {
        $objects = [];
        foreach (self::rows($db, 'SELECT name, type, tbl_name, sql FROM main.sqlite_schema') as $row) {
            $objects[strtolower($row['name'])] = $row;
        }
        return $objects;
    }

    public static function assert(PDO $db, PDO $expected, int $version = Database::CURRENT_SCHEMA_VERSION): void
    {
        $actualObjects = self::objects($db);
        foreach (self::objects($expected) as $name => $object) {
            if ($object['type'] !== 'table' || $name === 'sqlite_sequence') { continue; }
            $actual = $actualObjects[$name] ?? null;
            if ($actual === null && $version === 0) { continue; }
            if ($actual === null || $actual['type'] !== 'table') { self::refuse(); }
            $columns = SchemaColumnShape::columns($db, $name);
            $wanted = SchemaColumnShape::columns($expected, $name);
            $missing = SchemaColumnShape::validate($columns, $wanted, $version, $name);
            SchemaCheckShape::validate($actual['sql'], $object['sql'], $version, $missing);
            SchemaForeignShape::validate(SchemaForeignShape::foreignKeys($db, $name), SchemaForeignShape::foreignKeys($expected, $name), $version, $missing);
            SchemaIndexShape::validate(SchemaIndexShape::indexes($db, $name), SchemaIndexShape::indexes($expected, $name));

        }
    }











    public static function refuse(): never
    {
        throw new RuntimeException('Incompatible SQLite schema layout.');
    }
}
