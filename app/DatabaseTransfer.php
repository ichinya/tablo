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
    database_transfer_phase_mark(10);
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    DatabaseTransferPreflight::unowned($source);
    DatabaseTransferManifest::path(dirname($directory), basename($directory));
    if (!mkdir($directory, permissions: 0o700)) { throw new DatabaseFailure('configuration'); }
    try {
        $copy = database_transfer_snapshot_copy($source, $vault, $scratchPath, $directory);
        DatabaseTransferArchive::privateFile(DatabaseTransferManifest::path($directory, 'manifest.json'), $copy['bytes']);
        database_transfer_phase_mark(11);
        return $copy['digest'];
    } catch (\Throwable $error) { database_transfer_phase_mark(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_import_server(PDO $target, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
    #[\SensitiveParameter] string $scratchPath, bool $offline = false): string
{
    database_transfer_phase_mark(12);
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    try {
        if ($target->inTransaction() || WriteTransaction::owns($target)) { throw new \LogicException('Caller owns database transaction.'); }
        DatabaseTransferPreflight::stream($target);
        $manifest = DatabaseTransferVerification::manifest($directory, $vault);
        database_transfer_phase_mark(20);
        $scratch = DatabaseTransferPreflight::scratch($scratchPath);
        database_transfer_phase_mark(21);
        DatabaseTransferVerification::scratch($scratch, $directory, $manifest['header'], $vault);
        DatabaseTransferImport::server($target, $directory, $manifest['header'], $manifest['digest'], $vault);
        database_transfer_phase_mark(13);
        return $manifest['digest'];
    } catch (\Throwable $error) { database_transfer_phase_mark(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_import_sqlite(#[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory,
    #[\SensitiveParameter] string $scratchPath, #[\SensitiveParameter] string $targetPath, bool $offline = false): string
{
    database_transfer_phase_mark(16);
    database_transfer_persistence_require_offline([PHP_SAPI, $offline]);
    DatabaseTransferManifest::path(dirname($targetPath), basename($targetPath));
    if (file_exists($targetPath) || is_link($targetPath) || dirname($scratchPath) !== dirname($targetPath)) { throw new DatabaseFailure('configuration'); }
    try {
        $manifest = DatabaseTransferVerification::manifest($directory, $vault);
        database_transfer_phase_mark(20);
        $scratch = DatabaseTransferPreflight::scratch($scratchPath);
        database_transfer_phase_mark(21);
        DatabaseTransferVerification::scratch($scratch, $directory, $manifest['header'], $vault);
        DatabaseTransferImport::sqlite($scratch, $manifest['header']);
        DatabaseTransferVerification::database($scratch, $manifest['header'], $vault);
        $scratch = null;
        // Same-filesystem exclusive publication refuses an existing target atomically.
        if (!link($scratchPath, $targetPath)) { throw new DatabaseFailure('unavailable'); }
        if (!unlink($scratchPath)) { throw new DatabaseFailure('unavailable'); }
        database_transfer_phase_mark(17);
        return $manifest['digest'];
    } catch (\Throwable $error) { database_transfer_phase_mark(901); throw database_transfer_persistence_safe($error); }
}


function database_transfer_facade_verify(PDO $db, #[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] string $directory, #[\SensitiveParameter] string $scratchPath): string
{
    database_transfer_phase_mark(14);
    try {
        if (PHP_SAPI !== 'cli') { throw new DatabaseFailure('configuration'); }
        DatabaseTransferPreflight::stream($db);
        $manifest = DatabaseTransferVerification::manifest($directory, $vault);
        database_transfer_phase_mark(20);
        $scratch = DatabaseTransferPreflight::scratch($scratchPath);
        database_transfer_phase_mark(21);
        DatabaseTransferVerification::scratch($scratch, $directory, $manifest['header'], $vault);
        DatabaseTransferVerification::database($db, $manifest['header'], $vault);
        database_transfer_phase_mark(15);
        return $manifest['digest'];
    } catch (\Throwable $error) { database_transfer_phase_mark(901); throw database_transfer_persistence_safe($error); }
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
    database_transfer_phase_mark(20);
    $scratch = DatabaseTransferPreflight::scratch($scratchPath);
    database_transfer_phase_mark(21);
    database_transfer_phase_mark(22);
    DatabaseTransferPreflight::snapshot($source);
    database_transfer_phase_mark(23);
    try {
        database_transfer_phase_mark(24);
        DatabaseTransferPreflight::admitted($source, $vault);
        database_transfer_phase_mark(25);
        $remaining = DatabaseTransferManifest::MAX_CHUNKS; $rows = 0; $receipts = [];
        database_transfer_phase_mark(26);
        foreach (DatabaseTransferManifest::tables() as $table) {
            $receipt = $directory === null
                ? DatabaseTransferArchive::measure($source, $scratch, $table, $remaining)
                : DatabaseTransferArchive::write($source, $scratch, $table, $directory, $remaining);
            $remaining -= count($receipt['chunks']); $rows += $receipt['rows']; $receipts[] = $receipt;
        }
        database_transfer_phase_mark(27);
        database_transfer_phase_mark(28);
        $header = DatabaseTransferManifest::capture($source, $vault, $receipts);
        database_transfer_phase_mark(29);
        $digest = hash('sha256', JsonEncoding::encode($header));
        database_transfer_phase_mark(30);
        $bytes = JsonEncoding::encode(['payload' => $header, 'sha256' => $digest]);
        database_transfer_phase_mark(31);
        if (strlen($bytes) > DatabaseTransferManifest::MANIFEST_BYTES) { throw new DatabaseFailure('schema'); }
        database_transfer_phase_mark(32);
        DatabaseTransferPreflight::quiescent($scratch);
        $source->commit();
        database_transfer_phase_mark(33);
        return ['rows' => $rows, 'bytes' => $bytes, 'digest' => $digest];
    } catch (\Throwable $error) {
        if ($source->inTransaction()) { try { $source->rollBack(); } catch (\Throwable $discarded) { unset($discarded); } }
        throw database_transfer_persistence_safe($error);
    }
}

/** Temporary bounded Source107 observer; never part of production acceptance. */
function database_transfer_phase_mark(int $phase): void
{
    $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
    static $operation = 0, $previous = 0, $high = 0, $flags = 0;
    $handle = database_transfer_phase_handle($phase);
    if (!is_resource($handle)) { return; }
    try {
        if (!database_transfer_phase_memory($used, $peak)) { database_transfer_phase_close($handle); return; }
        $kind = $operation === 0 ? 1 : 2; $from = $previous;
        $terminal = database_transfer_phase_transition($phase, $operation, $previous, $flags);
        if (database_transfer_phase_should_record($kind, $phase, $flags, $peak, $high)) {
            $fields = $operation . "\t" . $from . "\t" . $phase . "\t" . $used . "\t" . $peak . "\t" . $flags;
            if (!database_transfer_phase_emit($handle, $kind, $fields, $flags)) { database_transfer_phase_close($handle); return; }
        }
        $high = max($high, $peak);
        if (!database_transfer_phase_observe($handle, $operation, $previous, $high, $flags)) { database_transfer_phase_close($handle); return; }
        if ($terminal !== 0) {
            database_transfer_phase_finish($handle, $operation, $previous, $terminal, $flags);
            database_transfer_phase_close($handle);
        }
    } catch (\Throwable $ignored) {
        unset($ignored); database_transfer_phase_close($handle);
    }
}

/** @return resource|false */
function database_transfer_phase_handle(int $phase): mixed
{
    static $ready = false, $handle = false;
    if ($ready) { return $handle; }
    $ready = true;
    if ($phase !== 10) { return false; }
    return $handle = database_transfer_phase_sink();
}

function database_transfer_phase_transition(int $phase, int &$operation, int &$previous, int &$flags): int
{
    $next = database_transfer_phase_operation($phase, $operation);
    if ($next < 0) { $flags |= 4; return 903; }
    $operation = $next;
    if (database_transfer_phase_known($phase)) { $previous = $phase; }
    if ($phase === 901) { return 901; }
    if ($phase === 15 && $operation === 6) { return 900; }
    return 0;
}

function database_transfer_phase_should_record(int $kind, int $phase, int $flags, int $peak, int $high): bool
{
    return $kind === 1 || (($flags & 4) === 0 && database_transfer_phase_known($phase) && $peak > $high);
}

/** @param resource $handle */
function database_transfer_phase_emit(mixed $handle, int $kind, string $fields, int &$flags): bool
{
    static $records = 0;
    if ($kind !== 4 && $records >= 63) { $flags |= 2; return true; }
    return database_transfer_phase_record($handle, $kind, ++$records, $fields) !== 0;
}

/** @param resource $handle */
function database_transfer_phase_observe(mixed $handle, int $operation, int $previous, int &$high, int &$flags): bool
{
    $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
    if (!database_transfer_phase_memory($used, $peak)) { return false; }
    if ($peak > $high) {
        $flags |= 1;
        $fields = $operation . "\t" . $previous . "\t1\t" . $used . "\t" . $peak . "\t" . $flags;
        if (!database_transfer_phase_emit($handle, kind: 3, fields: $fields, flags: $flags)) { return false; }
    }
    $high = memory_get_peak_usage(false);
    return $high <= 1_099_511_627_776;
}

/** @param resource $handle */
function database_transfer_phase_finish(mixed $handle, int $operation, int $previous, int $terminal, int $flags): void
{
    $flags |= 1;
    $terminal = database_transfer_phase_terminal($terminal, $flags);
    $used = memory_get_usage(false); $peak = memory_get_peak_usage(false);
    if (!database_transfer_phase_memory($used, $peak)) { return; }
    $fields = $operation . "\t" . $previous . "\t" . $terminal . "\t" . $used . "\t" . $peak . "\t" . $flags;
    database_transfer_phase_emit($handle, kind: 4, fields: $fields, flags: $flags);
}

function database_transfer_phase_terminal(int $terminal, int $flags): int
{
    if (($flags & 4) !== 0) { return 903; }
    if (($flags & 2) !== 0) { return 902; }
    return $terminal;
}

function database_transfer_phase_memory(int $used, int $peak): bool
{
    return $used >= 0 && $used <= $peak && $peak <= 1_099_511_627_776;
}

function database_transfer_phase_operation(int $phase, int $operation): int
{
    return match ($phase) {
        10 => match ($operation) { 0 => 1, 3 => 4, default => -1 },
        12 => $operation === 1 ? 2 : -1,
        14 => match ($operation) { 2 => 3, 5 => 6, default => -1 },
        16 => $operation === 4 ? 5 : -1,
        11 => in_array($operation, [1, 4], strict: true) ? $operation : -1,
        13 => $operation === 2 ? $operation : -1,
        15 => in_array($operation, [3, 6], strict: true) ? $operation : -1,
        17 => $operation === 5 ? $operation : -1,
        901 => $operation >= 1 && $operation <= 6 ? $operation : -1,
        default => database_transfer_phase_known($phase) && $operation >= 1 && $operation <= 6 ? $operation : -1,
    };
}

function database_transfer_phase_known(int $phase): bool
{
    return match ($phase) {
        10, 11, 12, 13, 14, 15, 16, 17, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33,
        40, 41, 42, 50, 51, 52, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71,
        80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91 => true,
        default => false,
    };
}

function database_transfer_phase_gate(): bool
{
    return PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80_500 && PHP_VERSION_ID < 80_600
        && getenv('TABLO_TEST_DB_DRIVER') === 'mariadb' && getenv('TABLO_TEST_ENGINE_CASE') === 'transfer-working-data'
        && ($_SERVER['argc'] ?? null) === 3 && is_array($_SERVER['argv'] ?? null) && ($_SERVER['argv'][1] ?? null) === 'transfer-working-data'
        && ($_SERVER['argv'][2] ?? null) === 'transfer-working-data';
}

function database_transfer_phase_directory(): ?string
{
    $source = database_transfer_phase_source();
    $fixture = getenv('TABLO_TEST_RUNTIME_ROOT'); $sink = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    if ($source === null || !is_string($fixture) || !is_string($sink)) { return null; }
    if (!database_transfer_phase_case($source, $fixture, $sink) || !database_transfer_phase_ancestors($source, $fixture, $sink)
        || !database_transfer_phase_plan($source)) { return null; }
    return $sink;
}

function database_transfer_phase_source(): ?string
{
    $nonce = getenv('TABLO_TEST_RUN_NONCE'); $source = getenv('TABLO_TEST_SOURCE_ROOT');
    if (!is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !is_string($source)) { return null; }
    if ($source !== str_replace('\\', replace: '/', subject: dirname(__DIR__)) || basename($source) !== 'source'
        || basename(dirname($source)) !== 'tablo-server-' . $nonce) { return null; }
    if (!database_transfer_phase_script($source)) { return null; }
    return $source;
}

function database_transfer_phase_script(string $source): bool
{
    $script = $source . '/tests/server-child.php';
    return $_SERVER['SCRIPT_FILENAME'] === $script && !is_link($script) && realpath($script) === $script;
}

function database_transfer_phase_case(string $source, string $fixture, string $sink): bool
{
    $nonce = getenv('TABLO_TEST_RUN_NONCE');
    if (!is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1) { return false; }
    $base = dirname($fixture, levels: 2);
    return dirname($base) === dirname($source) . '/cases' && preg_match('/\Amain-(?:0|[1-9][0-9]{0,3})\z/D', basename($base)) === 1
        && $fixture === $base . '/mariadb/tablo-server-' . $nonce && $sink === $base . '/closure';
}

function database_transfer_phase_ancestors(string $source, string $fixture, string $sink): bool
{
    $runtime = dirname($source); $base = dirname($fixture, levels: 2);
    foreach ([$runtime, $source, $source . '/tests', $runtime . '/evidence', $runtime . '/cases', $base, $base . '/mariadb', $fixture, $sink] as $directory) {
        if (!database_transfer_phase_regular_directory($directory)) { return false; }
    }
    $mode = fileperms($sink);
    return is_int($mode) && ($mode & 0o777) === 0o700;
}

function database_transfer_phase_plan(string $source): bool
{
    $plan = getenv('TABLO_TEST_CELL_PLAN'); $expected = getenv('TABLO_TEST_CELL_PLAN_SHA256');
    if ($plan !== dirname($source) . '/evidence/started-cell.json' || !is_string($expected)
        || preg_match('/\A[0-9a-f]{64}\z/D', $expected) !== 1) { return false; }
    return !is_link($plan) && is_file($plan) && realpath($plan) === $plan && hash_equals($expected, (string) hash_file('sha256', $plan));
}

function database_transfer_phase_regular_directory(string $directory): bool
{
    return !is_link($directory) && is_dir($directory) && str_replace('\\', replace: '/', subject: (string) realpath($directory)) === $directory;
}

/** @return resource|false */
function database_transfer_phase_sink(): mixed
{
    if (!database_transfer_phase_gate()) { return false; }
    try {
        return NativeWarning::ignore(static fn () => database_transfer_phase_sink_guarded());
    } catch (\Throwable $ignored) { unset($ignored); return false; }
}

/** @return resource|false */
function database_transfer_phase_sink_guarded(): mixed
{
    $directory = database_transfer_phase_directory();
    if ($directory === null) { return false; }
    $path = $directory . '/transfer-phase-s107.tsv';
    if (is_link($path) || file_exists($path)) { return false; }
    $handle = fopen($path, mode: 'xb');
    if (!is_resource($handle)) { return false; }
    $admitted = false;
    try {
        if (!database_transfer_phase_file($handle, $path) || !chmod($path, permissions: 0o600)
            || stream_set_write_buffer($handle, size: 0) !== 0) { return false; }
        $stat = fstat($handle);
        if (!is_array($stat) || ($stat['mode'] & 0o777) !== 0o600) { return false; }
        $admitted = true; return $handle;
    } finally { if (!$admitted) { database_transfer_phase_close($handle); } }
}

/** @param resource $handle */
function database_transfer_phase_file(mixed $handle, string $path): bool
{
    $opened = fstat($handle); $named = lstat($path);
    return is_array($opened) && is_array($named) && ($opened['mode'] & 0o170_000) === 0o100_000
        && ($named['mode'] & 0o170_000) === 0o100_000 && $opened['size'] === 0 && $opened['nlink'] === 1
        && $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino'];
}

/** @param resource $handle */
function database_transfer_phase_record(mixed $handle, int $kind, int $sequence, string $fields): int
{
    try {
        $line = $kind . "\t" . $sequence . "\t" . $fields . "\n";
        $bytes = strlen($line);
        if ($sequence < 1 || $sequence > 64 || $bytes > 128) { return 0; }
        if (NativeWarning::ignore(static fn (): int|false => fwrite($handle, $line)) !== $bytes) { return 0; }
        return $bytes;
    } catch (\Throwable $ignored) { unset($ignored); return 0; }
}

function database_transfer_phase_close(mixed $handle): void
{
    if (!is_resource($handle)) { return; }
    try { NativeWarning::ignore(static fn (): bool => fflush($handle)); } catch (\Throwable $ignored) { unset($ignored); }
    try { NativeWarning::ignore(static fn (): bool => fclose($handle)); } catch (\Throwable $ignored) { unset($ignored); }
}
