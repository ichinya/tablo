<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type ExpectedTable from ServerSchema as Table
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerCheckAdmission
{
    /** Full metadata parsing followed by compact, domain-separated SHA256 fingerprints.
     * These transient values use the same computational equality assumption as layout checksums.
     * No SQL/archive format is changed and no raw expression is accepted as a fingerprint.
     * @param Table $table
     * @return array<string, string> */
    public static function clauses(PDO $db, array $table): array { return server_check_admission_clauses($db, $table); }

    /** @param Table $table */
    public static function verify(PDO $db, array $table): void
    {
        $configuration = DatabaseSession::get($db)?->configuration;
        $clauses = $configuration?->engine === 'mariadb' && in_array($configuration->role, ['writer', 'control'], strict: true)
            ? ServerMetadataAdmission::checks($db, $table['name']) : server_check_admission_clauses($db, $table);
        if (count($clauses) !== count($table['checks'])) { self::refuse(); }
        foreach ($table['checks'] as $check) {
            if (($clauses[$check['name']] ?? null) === self::fingerprint(ServerExpression::canonical($check['expression']))) { continue; }
            self::refuse();
        }
    }
    /** @internal Input must be the complete validated canonical JSON, never raw SQL. */
    public static function fingerprint(string $canonical): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, data: "tablo/check-canonical/v1\0");
        hash_update($hash, $canonical);
        return hash_final($hash);
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

/** @param \Tablo\ServerSchema::ExpectedTable $table
 * @return array<string, string> */
function server_check_admission_clauses(PDO $db, array $table): array
{
    $mysql = DatabaseSession::get($db)?->configuration->engine === 'mysql';
    // MariaDB requires schema-level visibility even for direct CHECK metadata; callers use the admitted metadata account.
    $query = $mysql
        ? 'SELECT c.CONSTRAINT_NAME AS name, c.CHECK_CLAUSE AS clause, t.ENFORCED AS enforced FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = ?'
        : 'SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    $checks = ServerSchema::rows($db, $query, $mysql ? [$table['name'], 'CHECK'] : [$table['name']]);
    if (count($checks) !== count($table['checks'])) { throw new DatabaseFailure('schema'); }
    $clauses = [];
    foreach (array_keys($checks) as $key) {
        $check = $checks[$key] ?? throw new DatabaseFailure('schema'); unset($checks[$key]);
        if (!is_string($check['name'] ?? null) || !is_string($check['clause'] ?? null)) { throw new DatabaseFailure('schema'); }
        if (array_key_exists($check['name'], $clauses)) { throw new DatabaseFailure('schema'); }
        if ($mysql && ($check['enforced'] ?? null) !== 'YES') { throw new DatabaseFailure('schema'); }
        $name = $check['name']; $clause = $check['clause']; unset($check);
        if ($mysql) { $clause = ServerExpression::decodeMysqlMetadata($clause); }
        $clauses[$name] = ServerCheckAdmission::fingerprint($mysql ? ServerExpression::canonicalMysqlCheck($clause, $table) : ServerExpression::canonical($clause));
        unset($clause);
    }
    return $clauses;
}
