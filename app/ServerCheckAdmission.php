<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from ServerSchema
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerCheckAdmission
{
    /** @param Table $table */
    public static function verify(PDO $db, array $table): void
    {
        $clauses = server_check_admission_clauses($db, $table);
        foreach ($table['checks'] as $check) {
            if (($clauses[$check['name']] ?? null) === ServerExpression::canonical($check['expression'])) { continue; }
            self::refuse();
        }
    }
    /** @param Column $expected */
    public static function generated(array $expected, array $actual): void
    {
        if (!array_key_exists('generated', $expected)) {
            if (($actual['generation'] ?? '') !== '') { self::refuse(); }
            return;
        }
        $generation = SqlColumn::string($actual, 'generation');
        $kind = $expected['name'] === 'unresolved_site_id' ? 'virtual' : 'stored';
        if (ServerExpression::canonical($generation) !== ServerExpression::canonical($expected['generated'])
            || !str_contains(strtolower(SqlColumn::string($actual, 'extra')), $kind)) { self::refuse(); }
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}

/** @param \Tablo\ServerSchema::Table $table
 * @return array<string, string> */
function server_check_admission_clauses(PDO $db, array $table): array
{
    $mysql = DatabaseSession::get($db)?->configuration->engine === 'mysql';
    // MariaDB TABLE_CONSTRAINTS requires a non-SELECT privilege; the journal writer is SELECT-only.
    $query = $mysql
        ? 'SELECT c.CONSTRAINT_NAME AS name, c.CHECK_CLAUSE AS clause, t.ENFORCED AS enforced FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = ?'
        : 'SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    $checks = ServerSchema::rows($db, $query, $mysql ? [$table['name'], 'CHECK'] : [$table['name']]);
    if (count($checks) !== count($table['checks'])) { throw new DatabaseFailure('schema'); }
    $clauses = [];
    foreach ($checks as $check) {
        if (!is_string($check['name'] ?? null) || !is_string($check['clause'] ?? null)) { throw new DatabaseFailure('schema'); }
        if ($mysql && ($check['enforced'] ?? null) !== 'YES') { throw new DatabaseFailure('schema'); }
        $clause = $mysql ? ServerExpression::decodeMysqlMetadata($check['clause']) : $check['clause'];
        $clauses[$check['name']] = $mysql ? ServerExpression::canonicalMysqlCheck($clause, $table) : ServerExpression::canonical($clause);
    }
    return $clauses;
}
