<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type IndexInfo from SchemaShape
 * @phpstan-import-type IndexColumn from SchemaShape
 */
final class SchemaIndexShape
{
    public static function indexes(\PDO $db, string $table): array
    {
        $indexes = [];
        /** @var list<IndexInfo> $rows */
        $rows = SchemaShape::rows($db, 'PRAGMA main.index_list("' . $table . '")');
        foreach ($rows as $index) {
            $name = $index['name'];
            /** @var list<IndexColumn> $columns */
            $columns = SchemaShape::rows($db, 'PRAGMA main.index_xinfo("' . str_replace('"', replace: '""', subject: $name) . '")');
            foreach ($columns as &$column) { unset($column['cid']); }
            unset($column);
            $objects = SchemaShape::objects($db);
            $sql = $objects[strtolower($name)]['sql'] ?? null;
            $tokens = $sql === null ? [] : SqlSyntax::tokens($sql);
            $where = array_search('where', $tokens, strict: true);
            $key = $index['origin'] === 'c' ? $name : $index['origin'] . ':' . JsonEncoding::encode($columns);
            $indexes[$key] = [$index['unique'], $index['partial'], $columns, $where === false ? [] : array_slice($tokens, $where)];
        }
        ksort($indexes);
        return $indexes;
    }

    public static function validate(array $actual, array $wanted): void
    {
        foreach (array_keys($wanted) as $index) {
            if (($actual[$index] ?? null) === ($wanted[$index] ?? null)) { continue; }
            SchemaShape::refuse();
        }
    }

}
