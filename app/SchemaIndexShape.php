<?php
declare(strict_types=1);

namespace Tablo;

final class SchemaIndexShape
{
    public static function indexes(\PDO $db, string $table): array
    {
        $indexes = [];
        foreach (SchemaShape::rows($db, 'PRAGMA main.index_list("' . $table . '")') as $index) {
            $name = $index['name'];
            $columns = SchemaShape::rows($db, 'PRAGMA main.index_xinfo("' . str_replace('"', replace: '""', subject: $name) . '")');
            foreach ($columns as &$column) { unset($column['cid']); }
            unset($column);
            $objects = SchemaShape::objects($db);
            $sql = $objects[strtolower($name)]['sql'] ?? null;
            $tokens = $sql === null ? [] : SqlSyntax::tokens($sql);
            $where = array_search('where', $tokens, strict: true);
            $key = $index['origin'] === 'c' ? $name : $index['origin'] . ':' . json_encode($columns, JSON_THROW_ON_ERROR);
            $indexes[$key] = [$index['unique'], $index['partial'], $columns, $where === false ? [] : array_slice($tokens, $where)];
        }
        ksort($indexes);
        return $indexes;
    }

    public static function validate(array $actual, array $wanted): void
    {
        foreach ($wanted as $index => $shape) {
            if (($actual[$index] ?? null) === $shape) { continue; }
            SchemaShape::refuse();
        }
    }

}
