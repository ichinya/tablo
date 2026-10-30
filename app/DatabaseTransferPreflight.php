<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from DatabaseTransferManifest */
final class DatabaseTransferPreflight
{
    /** Transfer owns a dedicated source session; it stays read-only until the caller closes it. */
    public static function snapshot(PDO $db): void
    {
        database_transfer_source_snapshot($db);
    }

    /** A new private disk database bounds PHP working data and enforces the actual SQLite constraints. */
    public static function scratch(#[\SensitiveParameter] string $path): PDO
    {
        return database_transfer_sqlite_target_scratch($path);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table */
    public static function rowEnvelope(PDO $db, array $table): void
    {
        database_transfer_preflight_row_envelope($db, $table);
    }

    public static function quiescent(PDO $db): void
    {
        database_transfer_preflight_quiescent($db);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $values */
    public static function row(PDO $scratch, array $table, #[\SensitiveParameter] array $values): void
    {
        database_transfer_sqlite_target_row($scratch, $table, $values);
    }

    public static function admitted(PDO $db, #[\SensitiveParameter] TokenVault $vault): void
    {
        database_transfer_source_admitted($db, $vault);
    }

    public static function stream(PDO $db): void
    {
        database_transfer_source_stream($db);
    }

    public static function sourceMode(#[\SensitiveParameter] DatabaseConfiguration $configuration, string $mode): void
    {
        database_transfer_source_source_mode($configuration, $mode);
    }
    /** Refuse a caller-owned transaction before allocating private transfer files. */
    public static function unowned(PDO $db): void
    {
        database_transfer_source_unowned($db);
    }
}

// Internal operations for this fixed transfer module.


/**
 * @param \Tablo\DatabaseTransferManifest::Table $table */
function database_transfer_preflight_row_envelope(PDO $db, array $table): void
{
    database_transfer_preflight_storage($db, $table);
    $expression = database_transfer_preflight_bytes_expression($db, $table);
    if (ServerSchema::rows($db, 'SELECT 1 AS oversized FROM `' . $table['name'] . '` WHERE ' . $expression . ' > 98304 LIMIT 1') !== []) {
        throw new DatabaseFailure('schema');
    }
}


function database_transfer_preflight_quiescent(PDO $db): void
{
    if (ServerSchema::rows($db, 'SELECT id FROM worker_runtime WHERE run_id IS NOT NULL LIMIT 1') !== []
        || ServerSchema::rows($db, "SELECT site_id FROM notification_slots WHERE status = 'inflight' OR (status = 'pending' AND (claim IS NOT NULL OR lease_until IS NOT NULL)) LIMIT 1") !== []) {
        throw new DatabaseFailure('busy');
    }
}


/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @return array{rows: int, bytes: int} */
function database_transfer_preflight_footprint(PDO $db, array $table): array
{
    $expression = database_transfer_preflight_bytes_expression($db, $table);
    $row = ServerSchema::rows($db, 'SELECT COUNT(*) AS rows_count, COALESCE(SUM(' . $expression . '),0) AS bytes_count FROM `' . $table['name'] . '`')[0] ?? throw new DatabaseFailure('schema');
    return ['rows' => DatabaseInteger::checked($row['rows_count'] ?? null), 'bytes' => DatabaseInteger::checked($row['bytes_count'] ?? null)];
}


/** @param \Tablo\DatabaseTransferManifest::Table $table */
function database_transfer_preflight_storage(PDO $db, array $table): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { return; }
    $refusals = [];
    foreach ($table['columns'] as $column) {
        $type = $column['type'] === 'bigint' ? 'integer' : 'text';
        $refusals[] = 'typeof(`' . $column['name'] . "`) NOT IN ('" . $type . "','null')";
    }
    if (ServerSchema::rows($db, 'SELECT 1 AS unsupported_storage FROM `' . $table['name'] . '` WHERE ' . implode(' OR ', $refusals) . ' LIMIT 1') !== []) {
        throw new DatabaseFailure('schema');
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function database_transfer_preflight_bytes_expression(PDO $db, array $table): string
{
    $length = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'COALESCE(length(CAST(`%s` AS BLOB)),0)' : 'COALESCE(OCTET_LENGTH(`%s`),0)';
    $parts = [];
    foreach ($table['columns'] as $column) {
        if ($column['type'] === 'bigint') { continue; }
        $parts[] = sprintf($length, $column['name']);
    }
    return $parts === [] ? '0' : implode('+', $parts);
}

/** Transfer owns a dedicated source session; it stays read-only until the caller closes it. */
function database_transfer_source_snapshot(PDO $db): void
{
    database_transfer_source_unowned($db);
    $db->exec(DatabaseSession::server($db) ? 'SET SESSION TRANSACTION READ ONLY' : 'PRAGMA query_only = ON');
    $db->beginTransaction();
}


function database_transfer_source_admitted(PDO $db, #[\SensitiveParameter] TokenVault $vault): void
{
    database_transfer_source_stream($db);
    if (DatabaseSession::server($db)) { ServerSchema::admit($db); ServerKeyIdentity::authenticate($db, $vault); }
    if (!DatabaseSession::server($db)) {
        if (SchemaVersion::schemaVersion($db) !== 11) { throw new DatabaseFailure('schema'); }
        SchemaLayout::observe($db, 11);
    }
    $rows = 0; $bytes = 0;
    foreach (DatabaseTransferManifest::iterate() as $table) {
        database_transfer_preflight_row_envelope($db, $table); $size = database_transfer_preflight_footprint($db, $table);
        if ($size['rows'] > DatabaseTransferManifest::MAX_ROWS - $rows || $size['bytes'] > DatabaseTransferManifest::MAX_RAW_BYTES - $bytes) { throw new DatabaseFailure('schema'); }
        $rows += $size['rows']; $bytes += $size['bytes'];
        unset($table);
    }
    ExternalKeyAuthentication::authenticateExternalKey($db, $vault);
    database_transfer_preflight_quiescent($db);
}


function database_transfer_source_stream(PDO $db): void
{
    if (DatabaseSession::server($db)) { PdoMysqlOptions::stream($db); }
}


function database_transfer_source_source_mode(#[\SensitiveParameter] DatabaseConfiguration $configuration, string $mode): void
{
    if ($mode === 'transfer-source' && [PHP_SAPI, $configuration->role] !== ['cli', 'writer']) { throw new DatabaseFailure('configuration'); }
}

function database_transfer_source_unowned(PDO $db): void
{
    if ($db->inTransaction() || WriteTransaction::owns($db)) { throw new \LogicException('Caller owns database transaction.'); }
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { return; }
    // SQL-started SQLite transactions are not always visible through PDO on PHP8.2.
    // A failed BEGIN owns nothing: never roll back that caller's transaction.
    if ($db->exec('BEGIN DEFERRED') === false) { throw new \LogicException('Caller owns database transaction.'); }
    if ($db->exec('ROLLBACK') === false) { throw new DatabaseFailure('unavailable'); }
}


/** A new private disk database bounds PHP working data and enforces the actual SQLite constraints. */
function database_transfer_sqlite_target_scratch(#[\SensitiveParameter] string $path): PDO
{
    DatabaseTransferManifest::path(dirname($path), basename($path));
    $handle = NativeWarning::ignore(static fn () => fopen($path, mode: 'xb'));
    if (!is_resource($handle)) { throw new DatabaseFailure('configuration'); }
    fclose($handle);
    if (!chmod($path, permissions: 0o600)) { throw new DatabaseFailure('unavailable'); }
    $db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA cache_size = -512; PRAGMA temp_store = FILE');
    // The newly allocated private file needs one durable schema commit, not one per DDL/seed.
    WriteTransaction::run($db, static function () use ($db): void {
        SchemaReference::reference(11, ['endpoint' => true], $db);
        $db->exec('DELETE FROM notification_settings; DELETE FROM installation_settings; DELETE FROM worker_runtime; DELETE FROM self_update');
    });
    return $db;
}


/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $values */
function database_transfer_sqlite_target_row(PDO $scratch, array $table, #[\SensitiveParameter] array $values): void
{
    foreach (DatabaseTransferManifest::columns($table) as $index => $column) {
        $value = $values[$index] ?? null;
        if ($value === null || !in_array($column['name'], ['id', 'site_id', 'git_token_id', 'source_id', 'last_history_id', 'recovery_history_id', 'interruption_history_id'], strict: true)) { continue; }
        if (!is_int($value) || $value <= 0) { throw new DatabaseFailure('schema'); }
    }
    DatabaseTransferRows::insert($scratch, $table, $values);
}
