<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** @phpstan-type Objects list<array{name: string, kind: string}> */
final class ServerMetadataInventory
{
    /** @return Objects */
    public static function read(PDO $db): array
    {
        $rows = ServerSchema::rows($db, 'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind FROM information_schema.TABLES WHERE CAST(TABLE_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY) ORDER BY BINARY TABLE_NAME LIMIT 65');
        if (count($rows) > 64) { throw new DatabaseFailure('schema'); }
        $objects = [];
        foreach ($rows as $row) { $objects[] = ['name' => SqlColumn::string($row, 'name'), 'kind' => SqlColumn::string($row, 'kind')]; }
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $schema) {
            if (ServerSchema::rows($db, 'SELECT 1 AS forbidden FROM information_schema.' . $table . ' WHERE CAST(' . $schema . ' AS BINARY) = CAST(DATABASE() AS BINARY) LIMIT 1') !== []) { throw new DatabaseFailure('schema'); }
        }
        return $objects;
    }

    /** @param Objects $objects
     * @return list<string> */
    public static function tables(array $objects): array
    {
        $names = [];
        foreach ($objects as $object) {
            if ($object['kind'] !== 'BASE TABLE') { throw new DatabaseFailure('schema'); }
            $names[] = $object['name'];
        }
        return $names;
    }
}
