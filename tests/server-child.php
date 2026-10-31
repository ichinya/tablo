<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/RuntimeIni.php';
\Tablo\RuntimeIni::set('display_errors', '0'); \Tablo\RuntimeIni::set('log_errors', '0');
set_error_handler(static function (int $level): bool {
    if (!(error_reporting() & $level)) { return true; }
    throw new RuntimeException('Selected engine actor operation failed.');
});
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    if (count($argv) !== 3) { throw new RuntimeException('Actor arguments refused.'); }
    $case = \Tablo\Tests\Support\FixtureValue::string($argv[1] ?? null);
    $input = \Tablo\Tests\Support\FixtureValue::string($argv[2] ?? null);
    if ($input === 'transfer-working-data') { tablo_engine_transfer_working_data_actor($case); exit(0); }
    if (in_array($input, ['exit-before-lock', 'exit-after-lock', 'exit-after-ddl', 'exit-before-publication', 'exit-after-publication'], strict: true)) {
        tablo_engine_migration_exit_actor($case, $input);
        throw new RuntimeException('Migration exit actor returned without its required exit.');
    }
    if (in_array($input, ['migration-owner', 'migration-contender'], strict: true)) {
        tablo_engine_migration_actor($case, $input);
        exit(0);
    }
    $directory = \Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_TEST_ENGINE_INPUT_DIRECTORY'));
    if (dirname($input) !== $directory || is_link($directory) || is_link($input)
        || preg_match('/\Aengine-login-[0-9a-f]{24}\.json\z/D', basename($input)) !== 1) { throw new RuntimeException('Actor input ownership refused.'); }
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($input, length: 16_385));
    if (strlen($bytes) > 16_384) { throw new RuntimeException('Actor input bound exceeded.'); }
    $request = \Tablo\JsonRecord::decode($bytes);
    $password = \Tablo\SqlColumn::string($request, 'password'); $address = \Tablo\SqlColumn::string($request, 'address');
    $fixture = new \Tablo\Tests\Support\EngineFixture($case);
    $db = $fixture->database();
    $connectionId = $fixture->scalar('SELECT CONNECTION_ID()');
    fwrite(STDOUT, data: "ENGINE_READY\n"); fflush(STDOUT);
    if (fgets(STDIN) !== "go\n") { throw new RuntimeException('Actor release barrier refused.'); }
    try { $status = (new \Tablo\Auth($db))->login($password, $address) ? 'passed' : 'failed'; }
    catch (\Tablo\ValidationException) { $status = 'throttled'; }
    unset($password, $request, $bytes);
    fwrite(STDOUT, \Tablo\JsonEncoding::encode(['status' => $status, 'connectionId' => $connectionId]) . "\n");
    $fixture->close();
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'category' => $error instanceof \Tablo\DatabaseFailure ? $error->category : 'actor'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}

/** Two independently connected migration actors; all barriers use their owned stdin/stdout. */
function tablo_engine_migration_actor(string $case, string $operation): void
{
    $fixture = new \Tablo\Tests\Support\EngineFixture($case);
    $db = $fixture->maintenance();
    $connectionId = $fixture->scalar('SELECT CONNECTION_ID()', $db);
    fwrite(STDOUT, data: "ENGINE_MIGRATION_READY\n"); fflush(STDOUT);
    if (fgets(STDIN) !== "go\n") { throw new RuntimeException('Migration actor release refused.'); }
    if ($operation === 'migration-contender') {
        $probe = new \Tablo\ServerLock();
        if ($probe->acquire($db, 'migration')) {
            $probe->close();
            throw new RuntimeException('Expected native migration contention was absent.');
        }
        fwrite(STDOUT, data: "ENGINE_MIGRATION_CONTENDED\n"); fflush(STDOUT);
    }
    $migration = new \Tablo\ServerMigration($db, $fixture->vault, static function (string $phase, int $step) use ($operation): void {
        if ([$operation, $phase, $step] !== ['migration-owner', 'after-journal', 2]) { return; }
        fwrite(STDOUT, data: "ENGINE_MIGRATION_HELD\n"); fflush(STDOUT);
        if (fgets(STDIN) !== "continue\n") { throw new RuntimeException('Migration owner barrier refused.'); }
    });
    $migration->initialize(\Tablo\Tests\Support\EngineSchedule::receipt($case));
    $state = $migration->status();
    fwrite(STDOUT, \Tablo\JsonEncoding::encode(['status' => 'passed', 'connectionId' => $connectionId,
        'phase' => $state['phase'], 'version' => $state['logical_version']]) . "\n");
    $fixture->close();
}

/** Explicit client exit bypasses migration catch/finally; this is not a server crash or force-kill. */
function tablo_engine_migration_exit_actor(string $case, string $operation): void
{
    $fixture = new \Tablo\Tests\Support\EngineFixture($case); $db = $fixture->maintenance();
    fwrite(STDOUT, data: "ENGINE_MIGRATION_READY\n"); fflush(STDOUT);
    if (fgets(STDIN) !== "go\n") { throw new RuntimeException('Migration exit actor release refused.'); }
    if ($operation === 'exit-before-lock') { tablo_engine_migration_exit_boundary(); }
    if ($operation === 'exit-after-lock') {
        $lock = new \Tablo\ServerLock();
        if (!$lock->acquire($db, 'migration')) { throw new RuntimeException('Migration exit actor lock unavailable.'); }
        tablo_engine_migration_exit_boundary();
    }
    $barrier = \Tablo\Tests\Support\EngineProcess::migrationExitBarrier($operation, tablo_engine_migration_exit_boundary(...));
    $migration = new \Tablo\ServerMigration($db, $fixture->vault, $barrier);
    $migration->initialize(\Tablo\Tests\Support\EngineSchedule::receipt($case));
}

function tablo_engine_migration_exit_boundary(): never
{
    fwrite(STDOUT, data: "ENGINE_MIGRATION_EXIT_BOUNDARY\n"); fflush(STDOUT);
    if (fgets(STDIN) !== "exit\n") { throw new RuntimeException('Migration client exit release refused.'); }
    exit(86);
}

/** Cold helper admission, staging, export/import/verify and reverse transfer are all inside the peak. */
function tablo_engine_transfer_working_data_actor(string $case): void
{
    if ($case !== 'transfer-working-data') { throw new RuntimeException('Finite memory fixture refused.'); }
    $baseline = memory_get_usage(false); memory_reset_peak_usage();
    $result = hrtime(true); // COLD_PROFILE_ONLY
    $fixture = new \Tablo\Tests\Support\EngineFixture($case);
    $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/' . $case;
    tablo_engine_transfer_profile_marker($result); // COLD_PROFILE_ONLY
    $source = new PDO('sqlite:' . $root . '/source.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source->exec('PRAGMA query_only = ON');
    $payloadBytes = \Tablo\DatabaseTransferSequence::scalar($source, 'SELECT SUM(length(CAST(last_error AS BLOB))) AS value FROM sites');
    $result(); // COLD_PROFILE_ONLY
    $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
    $result(); // COLD_PROFILE_ONLY
    $imported = \Tablo\DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/archive', $root . '/import.sqlite', offline: true);
    $result(); // COLD_PROFILE_ONLY
    $server = $fixture->database();
    $result(); // COLD_PROFILE_ONLY
    $verified = \Tablo\DatabaseTransfer::verify($server, $fixture->vault, $root . '/archive', $root . '/verify.sqlite');
    $result(); // COLD_PROFILE_ONLY
    $reader = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), mode: 'transfer-source', vault: $fixture->vault);
    $buffered = tablo_engine_transfer_unbuffered($reader);
    $result(); // COLD_PROFILE_ONLY
    $reverse = \Tablo\DatabaseTransfer::export($reader, $fixture->vault, $root . '/reverse', $root . '/reverse-export.sqlite', offline: true);
    $result(); // COLD_PROFILE_ONLY
    $reimported = \Tablo\DatabaseTransfer::importSqlite($fixture->vault, $root . '/reverse', $root . '/staging.sqlite', $root . '/target.sqlite', offline: true);
    $result(); // COLD_PROFILE_ONLY
    $target = new PDO('sqlite:' . $root . '/target.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $final = \Tablo\DatabaseTransfer::verify($target, $fixture->vault, $root . '/archive', $root . '/final-verify.sqlite');
    if ([$imported, $verified, $final] !== [$digest, $digest, $digest] || $reimported !== $reverse || $buffered !== false) {
        throw new RuntimeException('Complete typed transfer integrity or unbuffered source refused.');
    }
    $result(); // COLD_PROFILE_ONLY
    $peak = memory_get_peak_usage(false); $working = $peak - $baseline;
    $result = ['status' => 'passed', 'tables' => count(\Tablo\DatabaseTransferManifest::TABLES), 'payloadBytes' => $payloadBytes,
        'baselinePhpBytes' => $baseline, 'peakPhpBytes' => $peak, 'peakPhpWorkingBytes' => $working,
        'nativeBufferedSource' => $buffered, 'allTypedDigestsPreserved' => true,
        'metricScope' => 'PHP used-memory peak from after autoload before any fixture/admission/helper warmup through complete roundtrip; external native allocator RSS is not this metric'];
    $source = null; $server = null; $reader = null; $target = null; $fixture->close();
    fwrite(STDOUT, \Tablo\JsonEncoding::encode($result) . "\n");
}

function tablo_engine_transfer_unbuffered(PDO $reader): bool
{
    $attribute = \Tablo\DatabaseInteger::checked(constant((PHP_VERSION_ID >= 80_400 ? 'Pdo\\Mysql::ATTR_' : 'PDO::MYSQL_ATTR_') . 'USE_BUFFERED_QUERY'));
    if (!in_array($reader->getAttribute($attribute), [false, 0], strict: true)) { throw new RuntimeException('Actual native transfer source remains buffered.'); }
    return false;
}

// COLD_PROFILE_HELPER_BEGIN
/** @param-out Closure(): void $marker */
function tablo_engine_transfer_profile_marker(int|float|Closure &$marker): void
{
    if ($marker instanceof Closure) { throw new RuntimeException('Diagnostic clock scalar required.'); }
    $started = $marker;
    $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/transfer-working-data';
    $timings = []; $index = 1;
    tablo_engine_transfer_profile($root, $started, $timings, index: 0);
    $marker = static function () use ($root, $started, &$timings, &$index): void {
        tablo_engine_transfer_profile($root, $started, $timings, $index++);
    };
}

/** Diagnostic-only writes and clock/bookkeeping allocations remain inside the original peak and timeout.
 * @param array<string, array{startedNs: ?int, completedNs: ?int}> $timings */
function tablo_engine_transfer_profile(string $root, int|float $started, array &$timings, int $index): void
{
    $phases = ['setup', 'export', 'serverImport', 'writerOpen', 'verify', 'readerOpen', 'reverseExport', 'sqliteImport', 'finalVerify'];
    if ($index < 0 || $index > count($phases)) { throw new RuntimeException('Finite diagnostic phase refused.'); }
    $elapsed = (int) (hrtime(true) - $started);
    if ($elapsed < 0 || $elapsed > 30_000_000_000) { throw new RuntimeException('Finite diagnostic clock refused.'); }
    if ($timings === []) {
        foreach ($phases as $name) { $timings[$name] = ['startedNs' => null, 'completedNs' => null]; }
    }
    $completed = $phases[$index - 1] ?? null; $current = $phases[$index] ?? null;
    if ($completed !== null) {
        $previous = $timings[$completed] ?? throw new RuntimeException('Finite diagnostic timing refused.');
        $previous['completedNs'] = $elapsed; $timings[$completed] = $previous;
    }
    if ($current !== null) {
        $timings[$current] = ['startedNs' => $index === 0 ? 0 : $elapsed, 'completedNs' => null];
    }
    $record = ['schema' => 'tablo/cold-transfer-phase/v1', 'nonce' => \Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_TEST_RUN_NONCE')),
        'case' => 'transfer-working-data', 'status' => $current === null ? 'complete' : 'partial', 'rootCause' => 'UNKNOWN',
        'currentPhase' => $current, 'lastCompletedPhase' => $completed, 'elapsedNs' => $elapsed,
        'phases' => $timings, 'profileOverheadIncluded' => true, 'originalActorAccepted' => false, 'issueAccepted' => false];
    tablo_engine_transfer_profile_write($root, $record);
}

function tablo_engine_transfer_profile_write(string $root, array $record): void
{
    $path = $root . '/cold-profile.json'; $temporary = $path . '.tmp';
    if (is_link($root) || is_link($path) || is_link($temporary)) { throw new RuntimeException('Linked diagnostic output refused.'); }
    $bytes = json_encode($record, JSON_THROW_ON_ERROR);
    if (strlen($bytes) > 8192) { throw new RuntimeException('Finite diagnostic output refused.'); }
    $output = fopen($temporary, mode: 'xb');
    if ($output === false) { throw new RuntimeException('Diagnostic output reservation refused.'); }
    try {
        if (!chmod($temporary, permissions: 0o600) || fwrite($output, $bytes) !== strlen($bytes) || !fflush($output)) {
            throw new RuntimeException('Diagnostic output write refused.');
        }
    } finally { fclose($output); }
    if (!rename($temporary, $path)) { throw new RuntimeException('Diagnostic atomic replacement refused.'); }
}
// COLD_PROFILE_HELPER_END
