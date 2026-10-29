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

/** The baseline/reset and product operation order are copied from 05c02a4 unchanged.
 * Added measurements are scalar cumulative peaks; there is no phase reset, warmup or I/O. */
function cold_phase_actor(string $case): void
{
    if ($case !== 'transfer-working-data') { throw new RuntimeException('Finite memory fixture refused.'); }
    $baseline = memory_get_usage(false); memory_reset_peak_usage();
    $fixture = new \Tablo\Tests\Support\EngineFixture($case);
    $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/' . $case;
    $fixtureUsed = memory_get_usage(false);
    $fixturePeak = memory_get_peak_usage(false);
    $source = new PDO('sqlite:' . $root . '/source.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source->exec('PRAGMA query_only = ON');
    $payloadBytes = \Tablo\DatabaseTransferSequence::scalar($source, 'SELECT SUM(length(CAST(last_error AS BLOB))) AS value FROM sites');
    $sourceUsed = memory_get_usage(false);
    $sourcePeak = memory_get_peak_usage(false);
    $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
    $exportUsed = memory_get_usage(false);
    $exportPeak = memory_get_peak_usage(false);
    $imported = \Tablo\DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/archive', $root . '/import.sqlite', offline: true);
    $serverImportUsed = memory_get_usage(false);
    $serverImportPeak = memory_get_peak_usage(false);
    $server = $fixture->database();
    $serverAdmissionUsed = memory_get_usage(false);
    $serverAdmissionPeak = memory_get_peak_usage(false);
    $verified = \Tablo\DatabaseTransfer::verify($server, $fixture->vault, $root . '/archive', $root . '/verify.sqlite');
    $serverVerifyUsed = memory_get_usage(false);
    $serverVerifyPeak = memory_get_peak_usage(false);
    $reader = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), mode: 'transfer-source', vault: $fixture->vault);
    $buffered = cold_phase_unbuffered($reader);
    $readerAdmissionUsed = memory_get_usage(false);
    $readerAdmissionPeak = memory_get_peak_usage(false);
    $reverse = \Tablo\DatabaseTransfer::export($reader, $fixture->vault, $root . '/reverse', $root . '/reverse-export.sqlite', offline: true);
    $reverseExportUsed = memory_get_usage(false);
    $reverseExportPeak = memory_get_peak_usage(false);
    $reimported = \Tablo\DatabaseTransfer::importSqlite($fixture->vault, $root . '/reverse', $root . '/staging.sqlite', $root . '/target.sqlite', offline: true);
    $sqliteImportUsed = memory_get_usage(false);
    $sqliteImportPeak = memory_get_peak_usage(false);
    $target = new PDO('sqlite:' . $root . '/target.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $final = \Tablo\DatabaseTransfer::verify($target, $fixture->vault, $root . '/archive', $root . '/final-verify.sqlite');
    $finalVerifyUsed = memory_get_usage(false);
    $finalVerifyPeak = memory_get_peak_usage(false);
    if ([$imported, $verified, $final] !== [$digest, $digest, $digest] || $reimported !== $reverse || $buffered !== false) {
        throw new RuntimeException('Complete typed transfer integrity or unbuffered source refused.');
    }
    $peak = memory_get_peak_usage(false); $working = $peak - $baseline;
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
