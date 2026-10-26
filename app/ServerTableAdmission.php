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
            $allTables = server_table_admission_source($db, $session, $version);
            $selection = server_table_admission_selection($allTables, $version);
            $tables = $selection['tables']; $expected = $selection['expected']; unset($selection);
            sort($expected, SORT_STRING);
            if ($version === 10 && $session?->configuration->engine === 'mariadb'
                && in_array($session->configuration->role, ['writer', 'control'], strict: true) && $session->metadataObservation === null) {
                // One admission owns one fresh observation; a factory snapshot stays borrowed.
                $session->metadataObservation = ServerMetadataAdmission::observe($session->configuration, $version, expectedTables: $allTables); $owned = true;
            }
            if (ServerMetadataAdmission::objects($db, $version, expectedTables: $allTables) !== $expected) { self::refuse(); }
            unset($allTables, $expected);
            if ($tables instanceof \Generator) { server_table_admission_record_tables($db, $tables); return; }
            foreach (array_keys($tables) as $key) {
                $table = $tables[$key] ?? throw new DatabaseFailure('schema'); unset($tables[$key]);
                ServerTableAdmission::verify($db, $table);
                unset($table);
            }
        } finally { if ($owned && $session !== null) { $session->metadataObservation = null; } }
    }

    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @internal Only an initially fresh MariaDB writer/control layout selects sealed records.
 * Ownership is still decided again after source validation by layout's original condition.
 * @return list<\Tablo\ServerSchema::AdmissionTable>|ServerAdmissionRecords */
function server_table_admission_source(PDO $db, ?DatabaseSession $session, int $version): array|ServerAdmissionRecords
{
    if ($version === 10 && $session?->configuration->engine === 'mariadb'
        && in_array($session->configuration->role, ['writer', 'control'], strict: true) && $session->metadataObservation === null) {
        return ServerAdmissionRecords::forConnection($db);
    }
    return ServerSchemaDescriptor::admissionTables(ServerSchema::layout($db));
}

/** @internal
 * @param \Generator<int, \Tablo\ServerSchema::AdmissionTable> $tables */
function server_table_admission_record_tables(PDO $db, \Generator $tables): void
{
    foreach ($tables as $table) { ServerTableAdmission::verify($db, $table); unset($table); }
}

/** @internal
 * @param list<\Tablo\ServerSchema::AdmissionTable>|ServerAdmissionRecords $source
 * @return array{tables: list<\Tablo\ServerSchema::AdmissionTable>|\Generator<int, \Tablo\ServerSchema::AdmissionTable>, expected: list<string>} */
function server_table_admission_selection(array|ServerAdmissionRecords $source, int $version): array
{
    if ($source instanceof ServerAdmissionRecords) { return ['tables' => $source->tables($version), 'expected' => $source->names($version)]; }
    $tables = array_values(array_filter($source, static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
    return ['tables' => $tables, 'expected' => array_column($tables, 'name')];
}
