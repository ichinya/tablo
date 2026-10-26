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
            server_table_coarse_layout(300);
            $allTables = ServerSchemaDescriptor::admissionTables(ServerSchema::layout($db));
            server_table_coarse_layout(301);
            $tables = array_values(array_filter($allTables, static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
            $expected = array_column($tables, 'name'); sort($expected, SORT_STRING);
            server_table_coarse_layout(302);
            if ($version === 10 && $session?->configuration->engine === 'mariadb'
                && in_array($session->configuration->role, ['writer', 'control'], strict: true) && $session->metadataObservation === null) {
                // One admission owns one fresh observation; a factory snapshot stays borrowed.
                $session->metadataObservation = ServerMetadataAdmission::observe($session->configuration, $version, expectedTables: $allTables); $owned = true;
            }
            server_table_coarse_layout(303);
            if (ServerMetadataAdmission::objects($db, $version, expectedTables: $allTables) !== $expected) { self::refuse(); }
            unset($allTables, $expected);
            server_table_coarse_layout(304); $ordinal = 0;
            foreach (array_keys($tables) as $key) {
                $table = $tables[$key] ?? throw new DatabaseFailure('schema'); unset($tables[$key]);
                ServerTableAdmission::verify($db, $table);
                unset($table);
                server_table_coarse_layout(305 + $ordinal++);
            }
            server_table_coarse_layout(323);
        } finally { if ($owned && $session !== null) { $session->metadataObservation = null; } }
    }

    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** Temporary diagnostic dispatcher; never autoload an observer. */
function server_table_coarse_layout(int $checkpoint): void
{
    if (class_exists(ColdTransferCoarsePeak::class, autoload: false)) { ColdTransferCoarsePeak::layout($checkpoint); }
}
