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
        // COLD_NESTED_BEGIN 100
        RuntimeIni::$coldNestedProbe?->__invoke(100, memory_get_peak_usage(false), $table['name']);
        // COLD_NESTED_END 100
        $rows = ServerSchema::rows($db, 'SELECT ENGINE AS engine, TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table['name']]);
        if (count($rows) !== 1 || ($rows[0]['engine'] ?? null) !== 'InnoDB' || ($rows[0]['collation'] ?? null) !== DatabaseSession::collation($db)) { self::refuse(); }
        // COLD_NESTED_BEGIN 110
        RuntimeIni::$coldNestedProbe?->__invoke(110, memory_get_peak_usage(false), $table['name']);
        // COLD_NESTED_END 110
        ServerColumnAdmission::verify($db, $table);
        // COLD_NESTED_BEGIN 120
        RuntimeIni::$coldNestedProbe?->__invoke(120, memory_get_peak_usage(false), $table['name']);
        // COLD_NESTED_END 120
        ServerIndexAdmission::verify($db, $table);
        // COLD_NESTED_BEGIN 130
        RuntimeIni::$coldNestedProbe?->__invoke(130, memory_get_peak_usage(false), $table['name']);
        // COLD_NESTED_END 130
        ServerForeignAdmission::verify($db, $table);
        // COLD_NESTED_BEGIN 140
        RuntimeIni::$coldNestedProbe?->__invoke(140, memory_get_peak_usage(false), $table['name']);
        // COLD_NESTED_END 140
        ServerCheckAdmission::verify($db, $table);
        // COLD_NESTED_BEGIN 150
        RuntimeIni::$coldNestedProbe?->__invoke(150, memory_get_peak_usage(false), $table['name']);
        // COLD_NESTED_END 150
    }
    public static function layout(PDO $db, int $version = 11): void
    {
        $session = DatabaseSession::get($db); $owned = false;
        try {
            $allTables = server_table_admission_source($db, $session, $version);
            $selection = server_table_admission_selection($allTables, $version);
            $tables = $selection['tables']; $expected = $selection['expected']; unset($selection);
            sort($expected, SORT_STRING);
            if ($version === 11 && $session?->configuration->engine === 'mariadb'
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

/** @internal Current migrators and MariaDB writer/control select sealed records independently of observation ownership.
 * Ownership is still decided again after source validation by layout's original condition.
 * @return list<\Tablo\ServerSchema::AdmissionTable>|ServerAdmissionRecords */
function server_table_admission_source(PDO $db, ?DatabaseSession $session, int $version): array|ServerAdmissionRecords
{
    if ($version === 11 && $session !== null
        && ($session->configuration->role === 'migrator'
            || ($session->configuration->engine === 'mariadb' && in_array($session->configuration->role, ['writer', 'control'], strict: true)))) {
        return ServerAdmissionRecords::forConnection($db);
    }
    $layout = ServerSchema::layout($db);
    $layout['tables'] = ServerSchemaProjection::tables($layout['tables'], $version);
    return ServerSchemaDescriptor::admissionTables($layout);
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
    $tables = array_values(array_filter($source, static fn (array $table): bool => ($version >= 10 || $table['name'] !== 'read_api_token') && ($version >= 11 || $table['name'] !== 'self_update')));
    return ['tables' => $tables, 'expected' => array_column($tables, 'name')];
}
