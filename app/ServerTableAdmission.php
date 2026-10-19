<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from ServerSchema
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
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
        $layout = ServerSchema::layout($db);
        $tables = array_values(array_filter($layout['tables'], static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
        $expected = array_column($tables, 'name'); sort($expected, SORT_STRING);
        if (ServerMetadataAdmission::objects($db, $version) !== $expected) { self::refuse(); }
        foreach ($tables as $table) { ServerTableAdmission::verify($db, $table); }
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}
