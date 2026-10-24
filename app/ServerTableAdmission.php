<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type ExpectedTable from ServerSchema as Table
 */
final class ServerTableAdmission
{
    /** @param Table $table */
    public static function verify(PDO $db, array $table): void
    {
        $rows = ServerSchema::rows($db, 'SELECT ENGINE AS engine, TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table['name']]);
        if (count($rows) !== 1 || ($rows[0]['engine'] ?? null) !== 'InnoDB' || ($rows[0]['collation'] ?? null) !== DatabaseSession::collation($db)) { self::refuse(); }
        ServerColumnAdmission::verify($db, $table);
        ServerIndexAdmission::verify($db, $table);
        ServerForeignAdmission::verify($db, $table);
        ServerCheckAdmission::verify($db, $table);
    }
    public static function layout(PDO $db, int $version = 10): void
    {
        $session = DatabaseSession::get($db); $owned = false;
        try {
            $allTables = server_table_admission_tables(ServerSchema::layout($db));
            $tables = array_values(array_filter($allTables, static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
            $expected = array_column($tables, 'name'); sort($expected, SORT_STRING);
            if ($version === 10 && $session?->configuration->engine === 'mariadb'
                && in_array($session->configuration->role, ['writer', 'control'], strict: true) && $session->metadataObservation === null) {
                // One admission owns one fresh observation; a factory snapshot stays borrowed.
                $session->metadataObservation = ServerMetadataAdmission::observe($session->configuration, $version, expectedTables: $allTables); $owned = true;
            }
            if (ServerMetadataAdmission::objects($db, $version, expectedTables: $allTables) !== $expected) { self::refuse(); }
            foreach ($tables as $table) { ServerTableAdmission::verify($db, $table); }
        } finally { if ($owned && $session !== null) { $session->metadataObservation = null; } }
    }

    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @internal Project only after the complete descriptor and every CREATE checksum have been validated.
 * @param \Tablo\ServerSchema::Layout $layout
 * @return list<\Tablo\ServerSchema::AdmissionTable> */
function server_table_admission_tables(array $layout): array
{
    $tables = $layout['tables']; unset($layout);
    $projected = [];
    foreach (array_keys($tables) as $key) {
        if (!array_key_exists($key, $tables)) { throw new DatabaseFailure('schema'); }
        $table = $tables[$key];
        $projected[] = ['name' => $table['name'], 'columns' => $table['columns'], 'primary' => $table['primary'],
            'checks' => $table['checks'], 'indexes' => $table['indexes'], 'foreign' => $table['foreign']];
        unset($tables[$key], $table);
    }
    unset($tables);
    return $projected;
}
