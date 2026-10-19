<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from ServerSchema
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerIndexAdmission
{
    /** @param Table $table */
    public static function verify(PDO $db, array $table): void
    {
        $indexes = ServerSchema::rows($db, 'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS position, SUB_PART AS prefix_length, INDEX_TYPE AS kind FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table['name']]);
        $expectedIndexes = array_merge([['name' => 'PRIMARY', 'columns' => $table['primary'], 'unique' => true]], $table['indexes']);
        $seen = [];
        foreach ($indexes as $row) {
            $name = SqlColumn::string($row, 'name');
            if (($row['prefix_length'] ?? null) !== null || ($row['kind'] ?? null) !== 'BTREE') { self::refuse(); }
            $seen[$name][] = [$row['column_name'] ?? null, DatabaseInteger::checked($row['non_unique'] ?? null)];
        }
        if (count($seen) !== count($expectedIndexes)) { self::refuse(); }
        foreach ($expectedIndexes as $index) {
            if (($seen[$index['name']] ?? null) === array_map(static fn (string $column): array => [$column, $index['unique'] ? 0 : 1], $index['columns'])) { continue; }
            self::refuse();
        }
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}
