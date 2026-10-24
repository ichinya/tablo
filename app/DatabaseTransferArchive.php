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
    $chunks = []; $data = ''; $rows = 0; $chunkRows = 0;
    $hash = hash_init('sha256');
    foreach (DatabaseTransferRows::read($source, $table) as $values) {
        $line = JsonEncoding::encode(DatabaseTransferRows::encode($values)) . "\n";
        if (strlen($line) > DatabaseTransferManifest::CHUNK_BYTES) { throw new DatabaseFailure('schema'); }
        if ($chunkRows === DatabaseTransferManifest::CHUNK_ROWS || strlen($data) + strlen($line) > DatabaseTransferManifest::CHUNK_BYTES) {
            $chunks[] = database_transfer_archive_chunk($directory, $table['name'], count($chunks), $data, $chunkRows); $data = ''; $chunkRows = 0;
        }
        if ($chunkRows === 0 && count($chunks) >= $remainingChunks) { throw new DatabaseFailure('schema'); }
        DatabaseTransferPreflight::row($scratch, $table, $values);
        $data .= $line; $chunkRows++; $rows++; hash_update($hash, $line);
        unset($line);
    }
    if ($chunkRows !== 0) { $chunks[] = database_transfer_archive_chunk($directory, $table['name'], count($chunks), $data, $chunkRows); }
    return ['name' => $table['name'], 'rows' => $rows, 'sha256' => hash_final($hash), 'chunks' => $chunks];
}


/**
 * @return \Tablo\DatabaseTransferArchive::Chunk */
function database_transfer_archive_chunk(#[\SensitiveParameter] ?string $directory, string $table, int $index, #[\SensitiveParameter] string $data, int $rows): array
{
    if ($directory !== null) { database_transfer_chunk_private_file(DatabaseTransferManifest::path($directory, $table . '.' . $index . '.jsonl'), $data); }
    return ['rows' => $rows, 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
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
 * @return \Generator<int, \Tablo\DatabaseTransferRows::Values>
 */
function database_transfer_chunk_read(#[\SensitiveParameter] string $directory, array $table, array $receipt): \Generator
{
    $rows = 0; $hash = hash_init('sha256');
    foreach ($receipt['chunks'] as $index => $chunk) {
        $path = DatabaseTransferManifest::path($directory, $table['name'] . '.' . $index . '.jsonl');
        if (is_link($path)) { throw new DatabaseFailure('configuration'); }
        $bytes = RequiredFile::bytes(file_get_contents($path, length: DatabaseTransferManifest::CHUNK_BYTES + 1));
        if (strlen($bytes) !== $chunk['bytes'] || !hash_equals($chunk['sha256'], hash('sha256', $bytes))) { throw new DatabaseFailure('schema'); }
        if (($bytes !== '' && !str_ends_with($bytes, "\n")) || substr_count($bytes, needle: "\n") !== $chunk['rows']) { throw new DatabaseFailure('schema'); }
        hash_update($hash, $bytes);
        $offset = 0;
        while (($end = strpos($bytes, needle: "\n", offset: $offset)) !== false) {
            $line = substr($bytes, $offset, $end - $offset); $offset = $end + 1;
            $rows++; $values = DatabaseTransferRows::line($line, $table);
            unset($line);
            yield $values;
            unset($values);
        }
        unset($bytes);
    }
    if ($rows !== $receipt['rows'] || !hash_equals($receipt['sha256'], hash_final($hash))) { throw new DatabaseFailure('schema'); }
}
