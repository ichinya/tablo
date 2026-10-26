<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * Private canonical typed chunks. Neither data nor a key is ever returned as a diagnostic.
 *
 * @phpstan-import-type Table from DatabaseTransferManifest
 *
 * @phpstan-type Chunk array{rows: int, bytes: int, sha256: string}
 *
 * @phpstan-type Receipt array{name: string, rows: int, sha256: string, chunks: list<Chunk>}
 *
 * @phpstan-type WriteChunk array{handle: resource|null, bytes: int, rows: int, hash: \HashContext}
 *
 * @phpstan-type ReadLine array{bytes: int, sha256: string}
 */
final class DatabaseTransferArchive
{
    /**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferArchive::Receipt */
    public static function write(PDO $source, PDO $scratch, array $table, #[\SensitiveParameter] string $directory, int $remainingChunks = DatabaseTransferManifest::MAX_CHUNKS): array
    {
        return database_transfer_archive_write($source, $scratch, $table, $directory, $remainingChunks);
    }

    /** @param \Tablo\DatabaseTransferManifest::Table $table
     * @return \Tablo\DatabaseTransferArchive::Receipt */
    public static function measure(PDO $source, PDO $scratch, array $table, int $remainingChunks): array
    {
        return database_transfer_archive_measure($source, $scratch, $table, $remainingChunks);
    }

    public static function privateFile(#[\SensitiveParameter] string $path, #[\SensitiveParameter] string $bytes): void
    {
        database_transfer_chunk_private_file($path, $bytes);
    }

    /**
     *
 * @param \Tablo\DatabaseTransferManifest::Table $table
     *
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
     *
 * @return \Generator<int, \Tablo\DatabaseTransferRows::Values>
     */
    public static function read(#[\SensitiveParameter] string $directory, array $table, array $receipt): \Generator
    {
        return database_transfer_chunk_read($directory, $table, $receipt);
    }

    /** @internal
     * @param \Tablo\DatabaseTransferManifest::Table $table
     * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
     * @param \Closure(\Tablo\DatabaseTransferRows::Values): void $consume */
    public static function visit(#[\SensitiveParameter] string $directory, array $table, array $receipt, \Closure $consume): void
    {
        database_transfer_chunk_visit($directory, $table, $receipt, $consume);
    }
}

// Internal operations for this fixed transfer module.

/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferArchive::Receipt */
function database_transfer_archive_write(PDO $source, PDO $scratch, array $table, #[\SensitiveParameter] string $directory, int $remainingChunks = DatabaseTransferManifest::MAX_CHUNKS): array
{
    return database_transfer_archive_stream($source, $scratch, $table, $directory, $remainingChunks);
}


/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferArchive::Receipt */
function database_transfer_archive_measure(PDO $source, PDO $scratch, array $table, int $remainingChunks): array
{
    return database_transfer_archive_stream($source, $scratch, $table, directory: null, remainingChunks: $remainingChunks);
}


/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferArchive::Receipt */
function database_transfer_archive_stream(PDO $source, PDO $scratch, array $table, #[\SensitiveParameter] ?string $directory, int $remainingChunks): array
{
    if ($remainingChunks < 0 || $remainingChunks > DatabaseTransferManifest::MAX_CHUNKS) { throw new DatabaseFailure('configuration'); }
    $chunks = []; $rows = 0; $chunk = database_transfer_archive_pending_chunk(); $hash = hash_init('sha256');
    try {
        foreach (DatabaseTransferRows::read($source, $table) as $values) {
            $line = DatabaseTransferWire::completeLine($values);
            if (strlen($line) > DatabaseTransferManifest::CHUNK_BYTES) { throw new DatabaseFailure('schema'); }
            if ($chunk['rows'] === DatabaseTransferManifest::CHUNK_ROWS || $chunk['bytes'] + strlen($line) > DatabaseTransferManifest::CHUNK_BYTES) {
                $chunks[] = database_transfer_archive_chunk($chunk); $chunk = database_transfer_archive_pending_chunk();
            }
            if ($chunk['rows'] === 0 && count($chunks) >= $remainingChunks) { throw new DatabaseFailure('schema'); }
            DatabaseTransferPreflight::row($scratch, $table, $values);
            database_transfer_archive_append($chunk, $directory, $table['name'], count($chunks), $line);
            $rows++; hash_update($hash, $line);
            unset($line, $values);
        }
        if ($chunk['rows'] !== 0) { $chunks[] = database_transfer_archive_chunk($chunk); }
        return ['name' => $table['name'], 'rows' => $rows, 'sha256' => hash_final($hash), 'chunks' => $chunks];
    } finally {
        // Failure can leave a partial private chunk; close it without masking the primary failure.
        $handle = $chunk['handle'];
        if (is_resource($handle)) { NativeWarning::ignore(static fn (): bool => fclose($handle)); }
    }
}


/** @return \Tablo\DatabaseTransferArchive::WriteChunk */
function database_transfer_archive_pending_chunk(): array
{
    return ['handle' => null, 'bytes' => 0, 'rows' => 0, 'hash' => hash_init('sha256')];
}

/** @param \Tablo\DatabaseTransferArchive::WriteChunk $chunk */
function database_transfer_archive_append(#[\SensitiveParameter] array &$chunk, #[\SensitiveParameter] ?string $directory, string $table, int $index, #[\SensitiveParameter] string $line): void
{
    if ($directory !== null) {
        $handle = $chunk['handle'] ?? database_transfer_archive_open($chunk, $directory, $table, $index);
        if (fwrite($handle, $line) !== strlen($line)) { throw new DatabaseFailure('unavailable'); }
    }
    $chunk['bytes'] += strlen($line); $chunk['rows']++; hash_update($chunk['hash'], $line);
}

/** @param \Tablo\DatabaseTransferArchive::WriteChunk $chunk
 * @return resource */
function database_transfer_archive_open(#[\SensitiveParameter] array &$chunk, #[\SensitiveParameter] string $directory, string $table, int $index): mixed
{
    $path = DatabaseTransferManifest::path($directory, $table . '.' . $index . '.jsonl');
    $handle = NativeWarning::ignore(static fn () => fopen($path, mode: 'xb'));
    if (!is_resource($handle)) { throw new DatabaseFailure('configuration'); }
    $chunk['handle'] = $handle;
    if (!chmod($path, permissions: 0o600)) { throw new DatabaseFailure('unavailable'); }
    return $handle;
}

/** @param \Tablo\DatabaseTransferArchive::WriteChunk $chunk
 * @return \Tablo\DatabaseTransferArchive::Chunk */
function database_transfer_archive_chunk(#[\SensitiveParameter] array &$chunk): array
{
    $handle = $chunk['handle'];
    if ($handle !== null) {
        if (!fflush($handle) || !fsync($handle)) { throw new DatabaseFailure('unavailable'); }
        if (!NativeWarning::ignore(static fn (): bool => fclose($handle))) { throw new DatabaseFailure('unavailable'); }
        $chunk['handle'] = null;
    }
    return ['rows' => $chunk['rows'], 'bytes' => $chunk['bytes'], 'sha256' => hash_final($chunk['hash'])];
}

function database_transfer_chunk_private_file(#[\SensitiveParameter] string $path, #[\SensitiveParameter] string $bytes): void
{
    $handle = NativeWarning::ignore(static fn () => fopen($path, mode: 'xb'));
    if (!is_resource($handle)) { throw new DatabaseFailure('configuration'); }
    try {
        if (!chmod($path, permissions: 0o600)) { throw new DatabaseFailure('unavailable'); }
        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) { throw new DatabaseFailure('unavailable'); }
    } finally { fclose($handle); }
}

/**
 *
 * @param \Tablo\DatabaseTransferManifest::Table $table
 *
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
 *
 * @param null|\Closure(\Tablo\DatabaseTransferRows::Values): void $consume
 *
 * @return \Generator<int, \Tablo\DatabaseTransferRows::Values>
 */
function database_transfer_chunk_read(#[\SensitiveParameter] string $directory, array $table, array $receipt, ?\Closure $consume = null): \Generator
{
    $rows = 0; $hash = hash_init('sha256');
    foreach ($receipt['chunks'] as $index => $chunk) {
        $path = DatabaseTransferManifest::path($directory, $table['name'] . '.' . $index . '.jsonl');
        if (is_link($path)) { throw new DatabaseFailure('configuration'); }
        $handle = NativeWarning::ignore(static fn () => fopen($path, mode: 'rb'));
        if (!is_resource($handle)) { throw new DatabaseFailure('unavailable'); }
        try {
            if (NativeWarning::ignore(static fn (): int => stream_set_read_buffer($handle, size: 0)) !== 0) { throw new DatabaseFailure('unavailable'); }
            $lines = database_transfer_chunk_authenticate($handle, $chunk);
            if (!NativeWarning::ignore(static fn (): bool => rewind($handle))) { throw new DatabaseFailure('unavailable'); }
            foreach ($lines as $commitment) {
                $line = database_transfer_chunk_line($handle, $commitment);
                hash_update($hash, $line); $line = substr($line, offset: 0, length: -1);
                $rows++; $values = DatabaseTransferRows::consumeLine($line, $table);
                unset($line);
                if ($consume === null) { yield $values; }
                if ($consume !== null) { $consume($values); }
                unset($values);
            }
            unset($lines, $commitment);
            $tail = NativeWarning::ignore(static fn () => fread($handle, length: 1));
            if ($tail === false || ($tail === '' && !feof($handle))) { throw new DatabaseFailure('unavailable'); }
            if ($tail !== '') { throw new DatabaseFailure('schema'); }
        } finally {
            // A suspended retained generator owns this descriptor until resumed or destroyed.
            database_transfer_chunk_close($handle);
        }
    }
    if ($rows !== $receipt['rows'] || !hash_equals($receipt['sha256'], hash_final($hash))) { throw new DatabaseFailure('schema'); }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
 * @param \Closure(\Tablo\DatabaseTransferRows::Values): void $consume */
function database_transfer_chunk_visit(#[\SensitiveParameter] string $directory, array $table, array $receipt, \Closure $consume): void
{
    // Visitor mode has no yields; exhausting the driver also validates final totals.
    foreach (database_transfer_chunk_read($directory, $table, $receipt, $consume) as $unused) { unset($unused); }
}

/** @param resource $handle
 * @param \Tablo\DatabaseTransferArchive::Chunk $chunk
 * @return list<\Tablo\DatabaseTransferArchive::ReadLine> */
function database_transfer_chunk_authenticate(#[\SensitiveParameter] mixed $handle, array $chunk): array
{
    $lines = []; $bytes = 0; $lineBytes = 0; $hash = hash_init('sha256'); $lineHash = hash_init('sha256');
    while (true) {
        $limit = min(16_384, DatabaseTransferManifest::CHUNK_BYTES + 1 - $bytes);
        $block = NativeWarning::ignore(static fn () => fread($handle, $limit));
        if ($block === false) { throw new DatabaseFailure('unavailable'); }
        if ($block === '') {
            if (!feof($handle)) { throw new DatabaseFailure('unavailable'); }
            break;
        }
        $bytes += strlen($block);
        if ($bytes > DatabaseTransferManifest::CHUNK_BYTES) { throw new DatabaseFailure('schema'); }
        hash_update($hash, $block);
        database_transfer_chunk_commitments($block, $lines, $lineBytes, $lineHash);
    }
    if ($bytes !== $chunk['bytes'] || !hash_equals($chunk['sha256'], hash_final($hash))
        || $lineBytes !== 0 || count($lines) !== $chunk['rows']) { throw new DatabaseFailure('schema'); }
    return $lines;
}

/** @param list<\Tablo\DatabaseTransferArchive::ReadLine> $lines */
function database_transfer_chunk_commitments(#[\SensitiveParameter] string $block, array &$lines, int &$lineBytes, \HashContext &$lineHash): void
{
    $blockBytes = strlen($block); $offset = 0;
    while ($offset < $blockBytes) {
        $end = strpos($block, needle: "\n", offset: $offset);
        $fragmentBytes = $end === false ? $blockBytes - $offset : $end - $offset + 1;
        hash_update($lineHash, substr($block, $offset, $fragmentBytes));
        $lineBytes += $fragmentBytes; $offset += $fragmentBytes;
        if ($end === false) { continue; }
        // Direct callers receive the same row bound as validated manifest receipts.
        if (count($lines) >= DatabaseTransferManifest::CHUNK_ROWS) { throw new DatabaseFailure('schema'); }
        $lines[] = ['bytes' => $lineBytes, 'sha256' => hash_final($lineHash)];
        $lineBytes = 0; $lineHash = hash_init('sha256');
    }
}

/** @param resource $handle
 * @param \Tablo\DatabaseTransferArchive::ReadLine $commitment */
function database_transfer_chunk_line(#[\SensitiveParameter] mixed $handle, array $commitment): string
{
    $line = '';
    while (strlen($line) < $commitment['bytes']) {
        $remaining = $commitment['bytes'] - strlen($line);
        $fragment = NativeWarning::ignore(static fn () => fread($handle, $remaining));
        if ($fragment === false) { throw new DatabaseFailure('unavailable'); }
        if ($fragment === '') { throw new DatabaseFailure(feof($handle) ? 'schema' : 'unavailable'); }
        $line .= $fragment; unset($fragment);
    }
    if (strlen($line) !== $commitment['bytes'] || !str_ends_with($line, "\n")
        || !hash_equals($commitment['sha256'], hash('sha256', $line))) { throw new DatabaseFailure('schema'); }
    return $line;
}

function database_transfer_chunk_close(#[\SensitiveParameter] mixed $handle): void
{
    if (is_resource($handle)) { NativeWarning::ignore(static fn (): bool => fclose($handle)); }
}
