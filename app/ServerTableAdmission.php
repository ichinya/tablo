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
        server_table_admission_phase(phase: 160);
        $session = DatabaseSession::get($db); $owned = false;
        try {
            $allTables = ServerSchemaDescriptor::admissionTables(ServerSchema::layout($db));
            server_table_admission_phase(phase: 161);
            $tables = array_values(array_filter($allTables, static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
            $expected = array_column($tables, 'name'); sort($expected, SORT_STRING);
            if ($version === 10 && $session?->configuration->engine === 'mariadb'
                && in_array($session->configuration->role, ['writer', 'control'], strict: true) && $session->metadataObservation === null) {
                // One admission owns one fresh observation; a factory snapshot stays borrowed.
                $session->metadataObservation = ServerMetadataAdmission::observe($session->configuration, $version, expectedTables: $allTables); $owned = true;
            }
            if (ServerMetadataAdmission::objects($db, $version, expectedTables: $allTables) !== $expected) { self::refuse(); }
            server_table_admission_phase(phase: 162);
            foreach ($tables as $table) { ServerTableAdmission::verify($db, $table); }
            server_table_admission_phase(phase: 163);
        } finally { if ($owned && $session !== null) { $session->metadataObservation = null; } server_table_admission_phase(phase: 164); }
    }

    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** Observer-only forwarding never autoloads the transfer facade or observer host. */
function server_table_admission_phase(int $phase): void
{
    if (function_exists('Tablo\\database_transfer_phase_mark')) { database_transfer_phase_mark($phase); }
}
