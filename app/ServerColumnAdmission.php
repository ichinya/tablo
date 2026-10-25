<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type ExpectedTable from ServerSchema as Table
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerColumnAdmission
{
    /** @param Table $table */
    public static function verify(PDO $db, array $table): void
    {
        server_column_admission_phase(phase: 170);
        $columns = ServerSchema::rows($db, 'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, EXTRA AS extra, GENERATION_EXPRESSION AS generation, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table['name']]);
        server_column_admission_phase(phase: 171);
        if (count($columns) !== count($table['columns'])) { self::refuse(); }
        $mysql = DatabaseSession::get($db)?->configuration->engine === 'mysql';
        foreach ($table['columns'] as $index => $expected) {
            $actual = SqlRows::required($columns[$index] ?? null, static fn (array $row): array => $row);
            unset($columns[$index]);
            $type = SqlColumn::string($actual, 'type');
            $expectedShape = ['name' => $expected['name'], 'type' => $expected['type'], 'nullable' => $expected['nullable'] ? 'YES' : 'NO', 'auto' => $expected['auto'] ?? false];
            $actualShape = ['name' => SqlColumn::string($actual, 'name'), 'type' => $type === 'bigint(20)' ? 'bigint' : $type,
                'nullable' => SqlColumn::string($actual, 'nullable'), 'auto' => str_contains(strtolower(SqlColumn::string($actual, 'extra')), 'auto_increment')];
            if ($expectedShape !== $actualShape) { self::refuse(); }
            if ($mysql) { $actual['generation'] = ServerExpression::decodeMysqlMetadata(SqlColumn::string($actual, 'generation')); }
            ServerCheckAdmission::generated($expected, $actual);
            if (!array_key_exists('generated', $expected) && !server_column_default_matches($db, $actual, $expected)) { self::refuse(); }
            if (in_array($expected['type'], ['longtext', 'varchar(80)'], strict: true) && ($actual['collation'] ?? null) !== DatabaseSession::collation($db)) { self::refuse(); }
        }
        server_column_admission_phase(phase: 172);
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @param \Tablo\ServerSchema::Column $expected */
function server_column_default_matches(PDO $db, array $actual, array $expected): bool
{
    return DatabaseSession::get($db)?->configuration->engine === 'mysql' ? ServerSchemaDescriptor::sameMysqlDefault($actual['default_value'] ?? null, $expected, SqlColumn::string($actual, 'extra'))
        : ServerSchemaDescriptor::sameDefault($actual['default_value'] ?? null, $expected['default']);
}

/** Observer-only forwarding never autoloads the transfer facade or observer host. */
function server_column_admission_phase(int $phase): void
{
    if (function_exists('Tablo\\database_transfer_phase_mark')) { database_transfer_phase_mark($phase); }
}
