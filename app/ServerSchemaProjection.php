<?php
declare(strict_types=1);

namespace Tablo;

/** Finite predecessor projection keeps historical 9/10 metadata constraints and digests intact.
 * @phpstan-import-type Table from ServerSchema */
final class ServerSchemaProjection
{
    /** @param Table $table
     * @return Table */
    public static function table(array $table, int $version): array
    {
        if ($version >= 11 || $table['name'] !== 'tablo_database_state') { return $table; }
        $table['create'] = str_replace('`logical_version` BETWEEN 0 AND 11', replace: '`logical_version` BETWEEN 0 AND 10', subject: $table['create']);
        foreach ($table['checks'] as $index => $check) {
            $table['checks'][$index]['expression'] = str_replace('`logical_version` BETWEEN 0 AND 11', replace: '`logical_version` BETWEEN 0 AND 10', subject: $check['expression']);
        }
        $table['checksum'] = hash('sha256', $table['create']);
        return $table;
    }

    public static function raw(array $table, int $version): array
    { return server_schema_projection_raw($table, $version); }

    /** @param list<Table> $tables
     * @return list<Table> */
    public static function tables(array $tables, int $version): array
    {
        $result = [];
        foreach ($tables as $table) {
            if ($version < 10 && $table['name'] === 'read_api_token') { continue; }
            if ($version < 11 && $table['name'] === 'self_update') { continue; }
            $result[] = self::table($table, $version);
        }
        return $result;
    }
}

function server_schema_projection_raw(array $table, int $version): array
{
        if ($version >= 11 || ($table['name'] ?? null) !== 'tablo_database_state') { return $table; }
        if (!is_string($table['create'] ?? null) || !is_array($table['checks'] ?? null)) { return $table; }
        $table['create'] = str_replace('`logical_version` BETWEEN 0 AND 11', replace: '`logical_version` BETWEEN 0 AND 10', subject: $table['create']);
        foreach (array_keys($table['checks']) as $index) {
            $check = JsonRecord::checked($table['checks'][$index] ?? null);
            $check['expression'] = str_replace('`logical_version` BETWEEN 0 AND 11', replace: '`logical_version` BETWEEN 0 AND 10', subject: SqlColumn::string($check, 'expression'));
            $table['checks'][$index] = $check;
        }
        $table['checksum'] = hash('sha256', $table['create']);
        return $table;
    }
