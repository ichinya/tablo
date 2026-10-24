<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type ExpectedTable from ServerSchema as Table
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerForeignAdmission
{
    /** @param Table $table */
    public static function verify(PDO $db, array $table): void
    {
        $foreign = ServerSchema::rows($db, 'SELECT k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS target_table, k.REFERENCED_COLUMN_NAME AS target_column, r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule, CAST(k.REFERENCED_TABLE_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY) AS local_target, CAST(r.UNIQUE_CONSTRAINT_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY) AS local_unique FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME', [$table['name']]);
        $expectedForeign = array_map(static fn (array $f): array => ['column_name' => $f['column'], 'target_table' => $f['table'], 'target_column' => $f['target'], 'delete_rule' => $f['onDelete'], 'update_rule' => $f['onUpdate'], 'local_target' => 1, 'local_unique' => 1], $table['foreign']);
        usort($expectedForeign, static fn (array $a, array $b): int => strcmp($a['column_name'], $b['column_name']));
        if ($foreign !== $expectedForeign) { self::refuse(); }
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}
