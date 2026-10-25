<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** The only transfer surface is a finite offline helper for the current fifteen tables. */
final class DatabaseTransfer
{
    public static function export(PDO $source, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $scratchPath, bool $offline = false): string
    {
        return database_transfer_facade_export($source, $vault, $directory, $scratchPath, $offline);
    }

    public static function importServer(PDO $target, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $scratchPath, bool $offline = false): string
    {
        return database_transfer_facade_import_server($target, $vault, $directory, $scratchPath, $offline);
    }

    public static function importSqlite(#[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $scratchPath, #[\SensitiveParameter] string $targetPath, bool $offline = false): string
    {
        return database_transfer_facade_import_sqlite($vault, $directory, $scratchPath, $targetPath, $offline);
    }

    public static function verify(PDO $db, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory, #[\SensitiveParameter] string $scratchPath): string
    {
        return database_transfer_facade_verify($db, $vault, $directory, $scratchPath);
    }

    public static function preflight(PDO $source, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $scratchPath, bool $offline = false): int
    {
        return database_transfer_facade_preflight($source, $vault, $scratchPath, $offline);
    }
}

// Internal operations for this fixed transfer module.

function database_transfer_facade_export(PDO $source, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
    #[\SensitiveParameter] string $scratchPath, bool $offline = false): string
{
    database_transfer_facade_phase(10);
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    DatabaseTransferPreflight::unowned($source);
    DatabaseTransferManifest::path(dirname($directory), basename($directory));
    if (!mkdir($directory, permissions: 0o700)) { throw new DatabaseFailure('configuration'); }
    try {
        $copy = database_transfer_snapshot_copy($source, $vault, $scratchPath, $directory);
        DatabaseTransferArchive::privateFile(DatabaseTransferManifest::path($directory, 'manifest.json'), $copy['bytes']);
        database_transfer_facade_phase(11);
        return $copy['digest'];
    } catch (\Throwable $error) { database_transfer_facade_phase(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_import_server(PDO $target, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
    #[\SensitiveParameter] string $scratchPath, bool $offline = false): string
{
    database_transfer_facade_phase(12);
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    try {
        if ($target->inTransaction() || WriteTransaction::owns($target)) { throw new \LogicException('Caller owns database transaction.'); }
        DatabaseTransferPreflight::stream($target);
        $manifest = DatabaseTransferVerification::manifest($directory, $vault);
        database_transfer_facade_phase(20);
        $scratch = DatabaseTransferPreflight::scratch($scratchPath);
        database_transfer_facade_phase(21);
        DatabaseTransferVerification::scratch($scratch, $directory, $manifest['header'], $vault);
        DatabaseTransferImport::server($target, $directory, $manifest['header'], $manifest['digest'], $vault);
        database_transfer_facade_phase(13);
        return $manifest['digest'];
    } catch (\Throwable $error) { database_transfer_facade_phase(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_import_sqlite(#[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
    #[\SensitiveParameter] string $scratchPath, #[\SensitiveParameter] string $targetPath, bool $offline = false): string
{
    database_transfer_facade_phase(16);
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    DatabaseTransferManifest::path(dirname($targetPath), basename($targetPath));
    if (file_exists($targetPath) || is_link($targetPath) || dirname($scratchPath) !== dirname($targetPath)) { throw new DatabaseFailure('configuration'); }
    try {
        $manifest = DatabaseTransferVerification::manifest($directory, $vault);
        database_transfer_facade_phase(20);
        $scratch = DatabaseTransferPreflight::scratch($scratchPath);
        database_transfer_facade_phase(21);
        DatabaseTransferVerification::scratch($scratch, $directory, $manifest['header'], $vault);
        DatabaseTransferImport::sqlite($scratch, $manifest['header']);
        DatabaseTransferVerification::database($scratch, $manifest['header'], $vault);
        $scratch = null;
        // Same-filesystem exclusive publication refuses an existing target atomically.
        if (!NativeWarning::ignore(static fn (): bool => link($scratchPath, $targetPath))) { throw new DatabaseFailure('unavailable'); }
        // Exclusive publication commits success. Cleanup cannot turn a published database into a failed import.
        try { NativeWarning::ignore(static fn (): bool => unlink($scratchPath)); }
        catch (\Throwable $discardedCleanup) { unset($discardedCleanup); }
        database_transfer_facade_phase(17);
        return $manifest['digest'];
    } catch (\Throwable $error) { database_transfer_facade_phase(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_verify(PDO $db, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory, #[\SensitiveParameter] string $scratchPath): string
{
    database_transfer_facade_phase(14);
    try {
        if (PHP_SAPI !== 'cli') { throw new DatabaseFailure('configuration'); }
        DatabaseTransferPreflight::stream($db);
        $manifest = DatabaseTransferVerification::manifest($directory, $vault);
        database_transfer_facade_phase(20);
        $scratch = DatabaseTransferPreflight::scratch($scratchPath);
        database_transfer_facade_phase(21);
        DatabaseTransferVerification::scratch($scratch, $directory, $manifest['header'], $vault);
        DatabaseTransferVerification::database($db, $manifest['header'], $vault);
        database_transfer_facade_phase(15);
        return $manifest['digest'];
    } catch (\Throwable $error) { database_transfer_facade_phase(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_preflight(PDO $source, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $scratchPath, bool $offline = false): int
{
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    return database_transfer_snapshot_copy($source, $vault, $scratchPath, directory: null)['rows'];
}

/** @param array{string, bool} $context */
function database_transfer_persistence_require_offline(array $context): void
{
    if ($context !== ['cli', true]) { throw new DatabaseFailure('configuration'); }
}




function database_transfer_persistence_safe(#[\SensitiveParameter] \Throwable $error): DatabaseFailure
{
    if ($error instanceof DatabaseFailure) { return new DatabaseFailure($error->category); }
    return $error instanceof \PDOException ? DatabaseFailure::fromNative($error) : new DatabaseFailure('schema');
}

/** @return array{rows: int, bytes: string, digest: string} */
function database_transfer_snapshot_copy(PDO $source, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $scratchPath, #[\SensitiveParameter] ?string $directory): array
{
    DatabaseTransferPreflight::unowned($source);
    database_transfer_facade_phase(20);
    $scratch = DatabaseTransferPreflight::scratch($scratchPath);
    database_transfer_facade_phase(21);
    database_transfer_facade_phase(22);
    DatabaseTransferPreflight::snapshot($source);
    database_transfer_facade_phase(23);
    try {
        database_transfer_facade_phase(24);
        DatabaseTransferPreflight::admitted($source, $vault);
        database_transfer_facade_phase(25);
        $remaining = DatabaseTransferManifest::MAX_CHUNKS; $rows = 0; $receipts = [];
        database_transfer_facade_phase(26);
        foreach (DatabaseTransferManifest::tables() as $table) {
            $receipt = $directory === null
                ? DatabaseTransferArchive::measure($source, $scratch, $table, $remaining)
                : DatabaseTransferArchive::write($source, $scratch, $table, $directory, $remaining);
            $remaining -= count($receipt['chunks']); $rows += $receipt['rows']; $receipts[] = $receipt;
        }
        database_transfer_facade_phase(27);
        database_transfer_facade_phase(28);
        $header = DatabaseTransferManifest::capture($source, $vault, $receipts);
        database_transfer_facade_phase(29);
        $digest = hash('sha256', JsonEncoding::encode($header));
        database_transfer_facade_phase(30);
        $bytes = JsonEncoding::encode(['payload' => $header, 'sha256' => $digest]);
        database_transfer_facade_phase(31);
        if (strlen($bytes) > DatabaseTransferManifest::MANIFEST_BYTES) { throw new DatabaseFailure('schema'); }
        database_transfer_facade_phase(32);
        DatabaseTransferPreflight::quiescent($scratch);
        $source->commit();
        database_transfer_facade_phase(33);
        return ['rows' => $rows, 'bytes' => $bytes, 'digest' => $digest];
    } catch (\Throwable $error) {
        if ($source->inTransaction()) { try { $source->rollBack(); } catch (\Throwable $discarded) { unset($discarded); } }
        throw database_transfer_persistence_safe($error);
    }
}

function database_transfer_facade_phase(int $phase): void
{
    if (function_exists('Tablo\\database_transfer_phase_mark')) { database_transfer_phase_mark($phase); }
}
