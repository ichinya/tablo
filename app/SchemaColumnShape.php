<?php
declare(strict_types=1);

namespace Tablo;

final class SchemaColumnShape
{
    public static function columns(\PDO $db, string $table): array
    {
        $columns = [];
        foreach (SchemaShape::rows($db, 'PRAGMA main.table_xinfo("' . $table . '")') as $row) {
            unset($row['cid']);
            $row['type'] = strtoupper($row['type']);
            $row['dflt_value'] = $row['dflt_value'] === null ? null : SqlSyntax::tokens($row['dflt_value']);
            $columns[$row['name']] = $row;
        }
        ksort($columns);
        return $columns;
    }

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
