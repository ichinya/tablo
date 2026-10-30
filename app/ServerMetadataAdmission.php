<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Transient, explicitly privileged observation under the documented quiescent DDL lifecycle.
 * Optional expectedTables is internal invocation reuse of the complete, unfiltered
 * ServerSchema::layout($selectedPdo)['tables'] or its admission projection, after source validation.
 * It must never come from SQL, an archive, environment or a report; it is not stored on a session.
 * @phpstan-import-type Objects from ServerMetadataInventory
 * @phpstan-import-type ExpectedTable from ServerSchema as Table
 * @phpstan-type Observation array{server: array<string, int|string>, account: string, objects: Objects, installation: list<array>, checks: array<string, array<string, string>>}
 */
final class ServerMetadataAdmission
{
    /** @param list<Table>|ServerAdmissionRecords|null $expectedTables Validated invocation-owned source tables only.
     * @return Observation */
    public static function observe(#[\SensitiveParameter] DatabaseConfiguration $configuration, ?int $version = null, array|ServerAdmissionRecords|null $expectedTables = null): array
    {
        $db = null;
        if ($expectedTables instanceof ServerAdmissionRecords) { $expectedTables->assertEngine($configuration->engine); }
        try {
            $metadata = $configuration->metadata(); $db = ServerPdoConnection::open($metadata);
            ServerMetadataPrivileges::verify($db, $metadata);
            $objects = ServerMetadataInventory::read($db);
            $names = ServerMetadataInventory::tables($objects);
            if ($version !== null) { self::expected($db, $names, $version, $expectedTables); }
            return ['server' => ServerMetadataIdentity::server($db), 'account' => ServerMetadataIdentity::grantee($db, $metadata), 'objects' => $objects,
                'installation' => ServerMetadataIdentity::installation($db, $names), 'checks' => server_metadata_check_observation($db, $configuration, $version, $expectedTables)];
        } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
        finally { $db = null; }
    }

    /** @param Observation $observation */
    public static function bind(PDO $db, array $observation): void
    {
        $names = ServerMetadataInventory::tables($observation['objects']);
        if (ServerMetadataIdentity::server($db) !== $observation['server']
            || JsonEncoding::encode(ServerMetadataIdentity::installation($db, $names)) !== JsonEncoding::encode($observation['installation'])) {
            throw new DatabaseFailure('schema');
        }
    }

    /** @param list<Table>|ServerAdmissionRecords|null $expectedTables Validated invocation-owned source tables only.
     * @return list<string> */
    public static function objects(PDO $db, ?int $version = null, array|ServerAdmissionRecords|null $expectedTables = null): array
    {
        $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
        if ($expectedTables instanceof ServerAdmissionRecords) { $expectedTables->assertEngine($session->configuration->engine); }
        $observation = $session->metadataObservation ?? self::observe($session->configuration, $version, $expectedTables);
        self::bind($db, $observation);
        $names = ServerMetadataInventory::tables($observation['objects']);
        if ($version !== null) { self::expected($db, $names, $version, $expectedTables); }
        return $names;
    }

    /** Domain-separated canonical CHECK fingerprints, fresh outside factory admission.
     * The factory's one-shot observation is cleared before returning its PDO.
     * @return array<string, string> */
    public static function checks(PDO $db, string $table): array
    {
        $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
        if ($session->configuration->engine !== 'mariadb' || !in_array($session->configuration->role, ['writer', 'control'], strict: true)) { throw new DatabaseFailure('schema'); }
        $observation = $session->metadataObservation ?? self::observe($session->configuration, 11);
        self::bind($db, $observation);
        return $observation['checks'][$table] ?? throw new DatabaseFailure('schema');
    }

    /** @param list<string> $names
     * @param list<Table>|ServerAdmissionRecords|null $expectedTables */
    private static function expected(PDO $db, array $names, int $version, array|ServerAdmissionRecords|null $expectedTables = null): void
    {
        server_metadata_expected_names($db, $names, $version, $expectedTables);
    }
}

/** @internal
 * @param list<string> $names
 * @param list<\Tablo\ServerSchema::ExpectedTable>|ServerAdmissionRecords|null $expectedTables */
function server_metadata_expected_names(PDO $db, array $names, int $version, array|ServerAdmissionRecords|null $expectedTables): void
{
    $source = server_metadata_expected_tables($db, $expectedTables);
    $expected = $source instanceof ServerAdmissionRecords ? $source->names($version)
        : array_column(array_values(array_filter($source, static fn (array $table): bool => ($version >= 10 || $table['name'] !== 'read_api_token') && ($version >= 11 || $table['name'] !== 'self_update'))), 'name');
    sort($expected, SORT_STRING);
    if (!in_array($version, [9, 10, 11], strict: true) || implode("\0", $names) !== implode("\0", $expected)) { throw new DatabaseFailure('schema'); }
}

/** Internal fallback uses sealed MariaDB records; explicit sources are returned unchanged.
 * @param list<\Tablo\ServerSchema::ExpectedTable>|ServerAdmissionRecords|null $expectedTables
 * @return list<\Tablo\ServerSchema::ExpectedTable>|ServerAdmissionRecords */
function server_metadata_expected_tables(PDO $db, array|ServerAdmissionRecords|null $expectedTables): array|ServerAdmissionRecords
{
    if ($expectedTables instanceof ServerAdmissionRecords) { $expectedTables->assertEngine(DatabaseSession::get($db)?->configuration->engine); return $expectedTables; }
    if ($expectedTables !== null) { return $expectedTables; }
    if (DatabaseSession::get($db)?->configuration->engine === 'mariadb') { return ServerAdmissionRecords::forConnection($db); }
    return ServerSchemaDescriptor::admissionTables(ServerSchema::layout($db));
}

/** Complete writer and control admission need privileged CHECK visibility. Migrators read their own post-DDL metadata.
 * @param list<\Tablo\ServerSchema::ExpectedTable>|ServerAdmissionRecords|null $expectedTables Complete source tables already validated in this invocation, never external data.
 * @return array<string, array<string, string>> */
function server_metadata_check_observation(PDO $db, DatabaseConfiguration $configuration, ?int $version, array|ServerAdmissionRecords|null $expectedTables = null): array
{
    if ($expectedTables instanceof ServerAdmissionRecords) { $expectedTables->assertEngine($configuration->engine); }
    if ($configuration->engine !== 'mariadb' || !in_array($configuration->role, ['writer', 'control'], strict: true) || $version !== 11) { return []; }
    $tables = server_metadata_expected_tables($db, $expectedTables); unset($expectedTables);
    if ($tables instanceof ServerAdmissionRecords) { return server_metadata_record_checks($db, $tables); }
    $checks = [];
    foreach (array_keys($tables) as $key) {
        $table = $tables[$key] ?? throw new DatabaseFailure('schema'); unset($tables[$key]);
        $checks[$table['name']] = ServerCheckAdmission::clauses($db, $table);
        unset($table);
    }
    return $checks;
}

/** @internal Each yielded table is complete and comes from the engine-bound validated source.
 * @return array<string, array<string, string>> */
function server_metadata_record_checks(PDO $db, ServerAdmissionRecords $records): array
{
    $checks = [];
    foreach ($records->tables() as $table) { $checks[$table['name']] = ServerCheckAdmission::clauses($db, $table); unset($table); }
    return $checks;
}
