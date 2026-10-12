<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type ExtendedColumn from SchemaShape
 * @phpstan-type Column array{name: string, type: string, notnull: int, dflt_value: list<string>|null, pk: int, hidden: int}
 */
final class SchemaColumnShape
{
    /** @return array<string, Column> */
    public static function columns(\PDO $db, string $table): array
    {
        $columns = [];
        /** @var list<ExtendedColumn> $rows */
        $rows = SchemaShape::rows($db, 'PRAGMA main.table_xinfo("' . $table . '")');
        foreach ($rows as $row) {
            unset($row['cid']);
            $row['type'] = strtoupper($row['type']);
            $row['dflt_value'] = $row['dflt_value'] === null ? null : SqlSyntax::tokens($row['dflt_value']);
            $columns[$row['name']] = $row;
        }
        ksort($columns);
        return $columns;
    }

    /** @return array<non-negative-int, array-key> */
    public static function validate(array $columns, array $wanted, int $version, string $table): array
    {
        $missing = array_diff(array_keys($wanted), array_keys($columns));
        if ($version === 0 && $table === 'sites') {
            if (array_diff($missing, ['github_token', 'git_token_id', 'version_json_path', 'health_check_mode',
                'health_json_path', 'health_json_operator', 'health_json_expected_value']) !== []) { SchemaShape::refuse(); }
            $wanted = array_intersect_key($wanted, $columns);
        }
        if ($columns !== $wanted) { SchemaShape::refuse(); }
        return $missing;
    }

}
