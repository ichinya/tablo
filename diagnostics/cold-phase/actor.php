<?php
declare(strict_types=1);

// Diagnostic copy only. Never selected by tests/server-child.php or the product verifier.
$root = dirname(__DIR__, levels: 2);
require_once $root . '/app/RuntimeIni.php';
\Tablo\RuntimeIni::set('display_errors', '0'); \Tablo\RuntimeIni::set('log_errors', '0');
set_error_handler(static function (int $level): bool {
    if (!(error_reporting() & $level)) { return true; }
    throw new RuntimeException('Selected engine diagnostic operation failed.');
});
try {
    require $root . '/vendor/autoload.php';
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 || count($argv) !== 3
        || ($argv[1] ?? null) !== 'transfer-working-data' || ($argv[2] ?? null) !== 'transfer-working-data') {
        throw new RuntimeException('Diagnostic actor selection refused.');
    }
    cold_phase_actor('transfer-working-data');
} catch (Throwable) {
    // No exception, SQL, path, argument, native diagnostic, trace or partial row is serialized.
    fwrite(STDERR, data: "COLD_PHASE_ACTOR_INCOMPLETE\n");
    exit(1);
}

/** The baseline/reset and product operation order are copied from 60b636a unchanged.
 * Added measurements are scalar cumulative peaks; there is no phase reset, warmup or I/O. */
function cold_phase_actor(string $case): void
{
    if ($case !== 'transfer-working-data') { throw new RuntimeException('Finite memory fixture refused.'); }
    // COLD_NESTED_SETUP_BEGIN
    $fixtureUsed = 0; $fixturePeak = 0;
    $sourceUsed = 0; $sourcePeak = 0;
    $exportUsed = 0; $exportPeak = 0;
    $serverImportUsed = 0; $serverImportPeak = 0;
    $serverAdmissionUsed = 0; $serverAdmissionPeak = 0;
    $serverVerifyUsed = 0; $serverVerifyPeak = 0;
    $readerAdmissionUsed = 0; $readerAdmissionPeak = 0;
    $reverseExportUsed = 0; $reverseExportPeak = 0;
    $sqliteImportUsed = 0; $sqliteImportPeak = 0;
    $finalVerifyUsed = 0; $finalVerifyPeak = 0;
    $GLOBALS['tablo_cold_nested_peak'] = 0;
    $GLOBALS['tablo_cold_nested_step'] = 0;
    $GLOBALS['tablo_cold_nested_table'] = 0;
    $GLOBALS['tablo_cold_nested_outer'] = 0;
    $GLOBALS['tablo_cold_nested_active_outer'] = 0;
    \Tablo\RuntimeIni::$coldNestedProbe = static function (int $step, int $peak, string $table = \Tablo\RuntimeIni::COLD_NESTED_NO_TABLE): void {
        if ($peak <= (int) $GLOBALS['tablo_cold_nested_peak']) { return; }
        $GLOBALS['tablo_cold_nested_peak'] = $peak;
        $GLOBALS['tablo_cold_nested_step'] = $step;
        $GLOBALS['tablo_cold_nested_outer'] = (int) $GLOBALS['tablo_cold_nested_active_outer'];
        $GLOBALS['tablo_cold_nested_table'] = match ($table) {
            '' => 0,
            'tablo_database_state' => 1,
            'tablo_writer_guard' => 2,
            'tablo_migration_journal' => 3,
            'users' => 4,
            'git_tokens' => 5,
            'installation_settings' => 6,
            'worker_runtime' => 7,
            'github_cooldowns' => 8,
            'notification_settings' => 9,
            'read_api_token' => 10,
            'self_update' => 11,
            'sites' => 12,
            'worker_progress' => 13,
            'check_history' => 14,
            'incidents' => 15,
            'incident_checkpoints' => 16,
            'notification_checkpoints' => 17,
            'notification_slots' => 18,
            'login_limits' => 19,
            default => 20,
        };
    };
    // COLD_NESTED_SETUP_END
    $baseline = memory_get_usage(false); memory_reset_peak_usage();
    // COLD_NESTED_BEGIN 2
    $GLOBALS['tablo_cold_nested_peak'] = $baseline;
    $GLOBALS['tablo_cold_nested_active_outer'] = 1;
    // COLD_NESTED_END 2
    $fixture = new \Tablo\Tests\Support\EngineFixture($case);
    $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/' . $case;
    $fixtureUsed = memory_get_usage(false);
    $fixturePeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 2;
    // COLD_NESTED_END 3
    $source = new PDO('sqlite:' . $root . '/source.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source->exec('PRAGMA query_only = ON');
    $payloadBytes = \Tablo\DatabaseTransferSequence::scalar($source, 'SELECT SUM(length(CAST(last_error AS BLOB))) AS value FROM sites');
    $sourceUsed = memory_get_usage(false);
    $sourcePeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 3;
    // COLD_NESTED_END 3
    $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
    $exportUsed = memory_get_usage(false);
    $exportPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 4;
    // COLD_NESTED_END 3
    $imported = \Tablo\DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/archive', $root . '/import.sqlite', offline: true);
    $serverImportUsed = memory_get_usage(false);
    $serverImportPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 5;
    // COLD_NESTED_END 3
    $server = $fixture->database();
    $serverAdmissionUsed = memory_get_usage(false);
    $serverAdmissionPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 6;
    // COLD_NESTED_END 3
    $verified = \Tablo\DatabaseTransfer::verify($server, $fixture->vault, $root . '/archive', $root . '/verify.sqlite');
    $serverVerifyUsed = memory_get_usage(false);
    $serverVerifyPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 7;
    // COLD_NESTED_END 3
    $reader = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), mode: 'transfer-source', vault: $fixture->vault);
    $buffered = cold_phase_unbuffered($reader);
    $readerAdmissionUsed = memory_get_usage(false);
    $readerAdmissionPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 8;
    // COLD_NESTED_END 3
    $reverse = \Tablo\DatabaseTransfer::export($reader, $fixture->vault, $root . '/reverse', $root . '/reverse-export.sqlite', offline: true);
    $reverseExportUsed = memory_get_usage(false);
    $reverseExportPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 9;
    // COLD_NESTED_END 3
    $reimported = \Tablo\DatabaseTransfer::importSqlite($fixture->vault, $root . '/reverse', $root . '/staging.sqlite', $root . '/target.sqlite', offline: true);
    $sqliteImportUsed = memory_get_usage(false);
    $sqliteImportPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 10;
    // COLD_NESTED_END 3
    $target = new PDO('sqlite:' . $root . '/target.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $final = \Tablo\DatabaseTransfer::verify($target, $fixture->vault, $root . '/archive', $root . '/final-verify.sqlite');
    $finalVerifyUsed = memory_get_usage(false);
    $finalVerifyPeak = memory_get_peak_usage(false);
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    // COLD_NESTED_BEGIN 3
    $GLOBALS['tablo_cold_nested_active_outer'] = 11;
    // COLD_NESTED_END 3
    if ([$imported, $verified, $final] !== [$digest, $digest, $digest] || $reimported !== $reverse || $buffered !== false) {
        throw new RuntimeException('Complete typed transfer integrity or unbuffered source refused.');
    }
    // COLD_NESTED_BEGIN 1
    \Tablo\RuntimeIni::$coldNestedProbe?->__invoke(1, memory_get_peak_usage(false));
    // COLD_NESTED_END 1
    $peak = memory_get_peak_usage(false); $working = $peak - $baseline;
    if ($peak > (int) $GLOBALS['tablo_cold_nested_peak']) {
        $GLOBALS['tablo_cold_nested_peak'] = $peak; $GLOBALS['tablo_cold_nested_step'] = 1;
        $GLOBALS['tablo_cold_nested_table'] = 0; $GLOBALS['tablo_cold_nested_outer'] = 11;
    }
    // Measurement is over. The first diagnostic collection and all encoding/cleanup/I/O follow it.
    $result = ['schema' => 'tablo/cold-phase-actor/v1', 'status' => 'complete', 'diagnostic' => true,
        'issueAccepted' => false, 'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN',
        'tables' => count(\Tablo\DatabaseTransferManifest::TABLES), 'payloadBytes' => $payloadBytes,
        'baselinePhpBytes' => $baseline, 'peakPhpBytes' => $peak, 'peakPhpWorkingBytes' => $working,
        'fixturePeakPhpBytes' => $fixturePeak, 'fixtureUsedPhpBytes' => $fixtureUsed, 'sourcePeakPhpBytes' => $sourcePeak, 'sourceUsedPhpBytes' => $sourceUsed,
        'exportPeakPhpBytes' => $exportPeak, 'exportUsedPhpBytes' => $exportUsed, 'serverImportPeakPhpBytes' => $serverImportPeak, 'serverImportUsedPhpBytes' => $serverImportUsed,
        'serverAdmissionPeakPhpBytes' => $serverAdmissionPeak, 'serverAdmissionUsedPhpBytes' => $serverAdmissionUsed, 'serverVerifyPeakPhpBytes' => $serverVerifyPeak, 'serverVerifyUsedPhpBytes' => $serverVerifyUsed,
        'readerAdmissionPeakPhpBytes' => $readerAdmissionPeak, 'readerAdmissionUsedPhpBytes' => $readerAdmissionUsed, 'reverseExportPeakPhpBytes' => $reverseExportPeak, 'reverseExportUsedPhpBytes' => $reverseExportUsed,
        'sqliteImportPeakPhpBytes' => $sqliteImportPeak, 'sqliteImportUsedPhpBytes' => $sqliteImportUsed, 'finalVerifyPeakPhpBytes' => $finalVerifyPeak, 'finalVerifyUsedPhpBytes' => $finalVerifyUsed,
        'nestedPeakPhpBytes' => (int) $GLOBALS['tablo_cold_nested_peak'],
        'nestedStep' => match ((int) $GLOBALS['tablo_cold_nested_step']) {
            1 => 'OUTER_END_UNWITNESSED',
            100 => 'TABLE_ENTRY',
            110 => 'TABLE_ENGINE_AFTER',
            120 => 'TABLE_COLUMNS_AFTER',
            130 => 'TABLE_INDEX_AFTER',
            140 => 'TABLE_FOREIGN_AFTER',
            150 => 'TABLE_CHECK_AFTER',
            200 => 'HEADER_ENTRY',
            201 => 'HEADER_READ',
            202 => 'HEADER_DECODE',
            203 => 'HEADER_ENVELOPE_CANONICAL',
            204 => 'HEADER_PAYLOAD_SHA',
            205 => 'HEADER_LAYOUT',
            206 => 'HEADER_COLUMNS_COMPARE',
            207 => 'HEADER_VALIDATED',
            208 => 'HEADER_RECEIPTS',
            209 => 'HEADER_CONTRACT_BEFORE',
            210 => 'HEADER_CONTRACT_AFTER',
            400 => 'SCHEMA_ASSERT_ENTRY',
            401 => 'SCHEMA_ACTUAL_OBJECTS',
            402 => 'SCHEMA_EXPECTED_TABLE_ENTRY',
            403 => 'SCHEMA_ACTUAL_COLUMNS',
            404 => 'SCHEMA_EXPECTED_COLUMNS',
            405 => 'SCHEMA_COLUMNS_VALIDATE',
            406 => 'SCHEMA_CHECKS',
            407 => 'SCHEMA_FOREIGN',
            408 => 'SCHEMA_INDEX',
            500 => 'WIRE_ENTRY',
            501 => 'WIRE_CELL_AFTER',
            502 => 'WIRE_EXIT',
            600 => 'ROWS_FETCH_AFTER',
            602 => 'ROWS_AFTER_CLOSE_RELEASE_BEFORE_CONSUME',
            620 => 'CODEC_CONSUME_ENTRY',
            621 => 'CODEC_JSON_AFTER',
            622 => 'CODEC_WIRE_CANONICAL_AFTER',
            623 => 'CODEC_TYPED_AFTER',
            624 => 'CODEC_ASCII_FRAMING_AFTER',
            625 => 'CODEC_ASCII_TYPED_AFTER',
            default => 'UNKNOWN',
        },
        'nestedTable' => match ((int) $GLOBALS['tablo_cold_nested_table']) {
            0 => 'NONE',
            1 => 'tablo_database_state',
            2 => 'tablo_writer_guard',
            3 => 'tablo_migration_journal',
            4 => 'users',
            5 => 'git_tokens',
            6 => 'installation_settings',
            7 => 'worker_runtime',
            8 => 'github_cooldowns',
            9 => 'notification_settings',
            10 => 'read_api_token',
            11 => 'self_update',
            12 => 'sites',
            13 => 'worker_progress',
            14 => 'check_history',
            15 => 'incidents',
            16 => 'incident_checkpoints',
            17 => 'notification_checkpoints',
            18 => 'notification_slots',
            19 => 'login_limits',
            20 => 'UNKNOWN',
            default => 'UNKNOWN',
        },
        'nestedOuterPhase' => match ((int) $GLOBALS['tablo_cold_nested_outer']) {
            1 => 'fixture',
            2 => 'source',
            3 => 'sqlite_export',
            4 => 'server_import',
            5 => 'writer_admission',
            6 => 'server_verify',
            7 => 'reader_admission',
            8 => 'reverse_export',
            9 => 'sqlite_import',
            10 => 'final_verify',
            11 => 'integrity',
            default => 'UNKNOWN',
        },
        'nativeBufferedSource' => $buffered, 'allTypedDigestsPreserved' => true];
    $source = null; $server = null; $reader = null; $target = null; $fixture->close();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
}

function cold_phase_unbuffered(PDO $reader): bool
{
    $attribute = \Tablo\DatabaseInteger::checked(constant((PHP_VERSION_ID >= 80_400 ? 'Pdo\\Mysql::ATTR_' : 'PDO::MYSQL_ATTR_') . 'USE_BUFFERED_QUERY'));
    if (!in_array($reader->getAttribute($attribute), [false, 0], strict: true)) { throw new RuntimeException('Actual native transfer source remains buffered.'); }
    return false;
}
