<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 *
 * @phpstan-import-type Header from DatabaseTransferManifest
 *
 * @phpstan-import-type Receipt from DatabaseTransferArchive
 *
 * @phpstan-import-type Table from DatabaseTransferManifest
 */
final class DatabaseTransferVerification
{
    /**
 * @return array{header: \Tablo\DatabaseTransferManifest::Header, digest: string} */
    public static function manifest(#[\SensitiveParameter] string $directory, #[\SensitiveParameter] TokenVault $vault): array
    {
        return database_transfer_header_manifest($directory, $vault);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
    public static function scratch(PDO $scratch, #[\SensitiveParameter] string $directory, array $header, #[\SensitiveParameter] TokenVault $vault): void
    {
        database_transfer_verification_scratch($scratch, $directory, $header, $vault);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt */
    public static function table(PDO $db, array $table, array $receipt): void
    {
        database_transfer_verification_table($db, $table, $receipt);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
    public static function database(PDO $db, array $header, #[\SensitiveParameter] TokenVault $vault): void
    {
        database_transfer_verification_database($db, $header, $vault);
    }
}

// Internal operations for this fixed transfer module.


/**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_verification_scratch(PDO $scratch, #[\SensitiveParameter] string $directory, array $header, #[\SensitiveParameter] TokenVault $vault): void
{
    foreach (DatabaseTransferManifest::iterate($header['columns']) as $index => $table) {
        $receipt = $header['tables'][$index] ?? throw new DatabaseFailure('schema');
        DatabaseTransferArchive::visit($directory, $table, $receipt, static function (array $values) use ($scratch, $table): void {
            DatabaseTransferPreflight::row($scratch, $table, $values);
        });
        DatabaseTransferPreflight::rowEnvelope($scratch, $table);
        unset($table);
    }
    DatabaseTransferPreflight::quiescent($scratch);
    if (DatabaseTransferSequence::watermark($scratch) > $header['watermark']) { throw new DatabaseFailure('schema'); }
    ExternalKeyAuthentication::authenticateExternalKey($scratch, $vault);
}


/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt */
function database_transfer_verification_table(PDO $db, array $table, array $receipt): void
{
    $rows = 0; $hash = hash_init('sha256');
    foreach (DatabaseTransferRows::read($db, $table) as $values) {
        $rows++; hash_update($hash, DatabaseTransferWire::completeLine($values));
        unset($values);
    }
    if ([$rows, hash_final($hash)] !== [$receipt['rows'], $receipt['sha256']]) { throw new DatabaseFailure('schema'); }
}


/**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_verification_database(PDO $db, array $header, #[\SensitiveParameter] TokenVault $vault): void
{
    foreach (DatabaseTransferManifest::iterate($header['columns']) as $index => $table) {
        database_transfer_verification_table($db, $table, $header['tables'][$index] ?? throw new DatabaseFailure('schema'));
        unset($table);
    }
    $floors = DatabaseTransferSequence::floors($db);
    foreach ($header['sequences'] as $table => $floor) { if (SqlColumn::integer($floors, $table) < $floor) { throw new DatabaseFailure('schema'); } }
    $rows = ServerSchema::rows($db, 'SELECT installation_id FROM notification_settings WHERE id = 1');
    // SQLite schema10 represents its activation frontier with sqlite_sequence.
    // A server rollback may reserve IDs beyond its separately committed watermark.
    $watermark = DatabaseSession::server($db) ? $header['watermark']
        : max($header['watermark'], $header['sequences']['check_history']);
    if (SqlColumn::string($rows[0] ?? [], 'installation_id') !== $header['installation']
        || DatabaseTransferSequence::watermark($db) !== $watermark) { throw new DatabaseFailure('schema'); }
    ExternalKeyAuthentication::authenticateExternalKey($db, $vault);
}

/**
 * @return array{header: \Tablo\DatabaseTransferManifest::Header, digest: string} */
function database_transfer_header_manifest(#[\SensitiveParameter] string $directory, #[\SensitiveParameter] TokenVault $vault): array
{
    $path = DatabaseTransferManifest::path($directory, 'manifest.json');
    if (is_link($path)) { throw new DatabaseFailure('configuration'); }
    $bytes = RequiredFile::bytes(file_get_contents($path, length: DatabaseTransferManifest::MANIFEST_BYTES + 1));
    if (strlen($bytes) > DatabaseTransferManifest::MANIFEST_BYTES) { throw new DatabaseFailure('schema'); }
    $envelope = JsonRecord::decode($bytes);
    if (JsonEncoding::encode($envelope) !== $bytes) { throw new DatabaseFailure('schema'); }
    database_transfer_receipt_keys($envelope, ['payload', 'sha256']);
    /** @var array{payload: mixed, sha256: mixed} $envelope Exact keys checked above. */
    $header = JsonRecord::checked($envelope['payload']);
    database_transfer_receipt_keys($header, ['format', 'engine', 'logicalVersion', 'layout', 'columns', 'installation', 'verifier', 'sequences', 'watermark', 'tables']);
    /** @var array{format: mixed, engine: mixed, logicalVersion: mixed, layout: mixed, columns: mixed, installation: mixed, verifier: mixed, sequences: mixed, watermark: mixed, tables: mixed} $header Exact keys checked above. */
    $digest = hash('sha256', JsonEncoding::encode($header));
    $expected = [1, 10, DatabaseTransferManifest::layout(), DatabaseTransferManifest::contract()];
    if ([$header['format'], $header['logicalVersion'], $header['layout'], $header['columns']] !== $expected
        || ($envelope['sha256']) !== $digest) { throw new DatabaseFailure('schema'); }
    $installation = SqlColumn::string($header, 'installation');
    if (!hash_equals($vault->serverVerifier($installation), SqlColumn::string($header, 'verifier'))) { throw new DatabaseFailure('key'); }
    if (!in_array($header['engine'], ['sqlite', 'mysql', 'mariadb'], strict: true)) { throw new DatabaseFailure('schema'); }
    database_transfer_receipt_sequences($header);
    $tables = JsonRecord::checked($header['tables']);
    if (!array_is_list($tables)) { throw new DatabaseFailure('schema'); }
    $names = []; $chunks = 0;
    foreach (array_keys($tables) as $index) {
        $table = JsonRecord::checked($tables[$index] ?? null);
        $names[] = SqlColumn::string($table, 'name'); database_transfer_receipt_receipt($table);
        $chunks += count(JsonRecord::checked($table['chunks'] ?? null));
        if ($chunks > DatabaseTransferManifest::MAX_CHUNKS) { throw new DatabaseFailure('schema'); }
    }
    if ($names !== DatabaseTransferManifest::TABLES) { throw new DatabaseFailure('schema'); }
    $header['columns'] = $expected[3];
    /** @var \Tablo\DatabaseTransferManifest::Header $header Structure and finite identities validated above. */
    return ['header' => $header, 'digest' => $digest];
}


/**
 * @param array $header */
function database_transfer_receipt_sequences(#[\SensitiveParameter] array $header): void
{
    $sequences = JsonRecord::checked($header['sequences'] ?? null);
    if (array_keys($sequences) !== ['sites', 'git_tokens', 'check_history']) { throw new DatabaseFailure('schema'); }
    $values = [SqlColumn::integer($sequences, 'sites'), SqlColumn::integer($sequences, 'git_tokens'),
        SqlColumn::integer($sequences, 'check_history'), SqlColumn::integer($header, 'watermark')];
    foreach ($values as $value) {
        if ($value < 0 || $value === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
    }
}


/**
 * @param array $receipt */
function database_transfer_receipt_receipt(array $receipt): void
{
    database_transfer_receipt_keys($receipt, ['name', 'rows', 'sha256', 'chunks']);
    /** @var array{name: mixed, rows: mixed, sha256: mixed, chunks: mixed} $receipt Exact keys checked above. */
    $chunks = JsonRecord::checked($receipt['chunks']);
    if (!array_is_list($chunks)) { throw new DatabaseFailure('schema'); }
    $rows = SqlColumn::integer($receipt, 'rows'); $total = 0;
    database_transfer_receipt_digest(SqlColumn::string($receipt, 'sha256'));
    foreach (array_keys($chunks) as $index) {
        $record = JsonRecord::checked($chunks[$index] ?? null);
        database_transfer_receipt_keys($record, ['rows', 'bytes', 'sha256']);
        $size = SqlColumn::integer($record, 'bytes'); $count = SqlColumn::integer($record, 'rows');
        if ($size < 1 || $size > DatabaseTransferManifest::CHUNK_BYTES || $count < 1 || $count > DatabaseTransferManifest::CHUNK_ROWS) { throw new DatabaseFailure('schema'); }
        database_transfer_receipt_digest(SqlColumn::string($record, 'sha256')); $total += $count;
    }
    if ($total !== $rows) { throw new DatabaseFailure('schema'); }
}


function database_transfer_receipt_digest(string $value): void
{
    if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) { throw new DatabaseFailure('schema'); }
}


/** @param list<string> $expected */
function database_transfer_receipt_keys(#[\SensitiveParameter] array $record, array $expected): void
{
    $actual = array_keys($record); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
    if ($actual !== $expected) { throw new DatabaseFailure('schema'); }
}
