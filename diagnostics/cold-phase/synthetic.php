<?php
declare(strict_types=1);
require __DIR__ . '/receipt.php';
require __DIR__ . '/frozen.php';

$checks = 0;
function cold_phase_check(bool $condition): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException('Synthetic scalar diagnostic control failed.'); }
}
function cold_phase_control(array $record, array $transport, bool $accepted): array
{
    $bytes = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
    $safe = cold_phase_project('mysql', $bytes, '', $transport);
    cold_phase_check($safe['measurementComplete'] === $accepted);
    cold_phase_check($safe['issueAccepted'] === false && $safe['originalActorAccepted'] === false);
    cold_phase_check(strlen(json_encode($safe, JSON_THROW_ON_ERROR)) < 4096);
    foreach ($safe as $value) { cold_phase_check(is_scalar($value) || $value === null); }
    cold_phase_check(!str_contains(json_encode($safe, JSON_THROW_ON_ERROR), 'PRIVATE_SQL_PATH_SECRET'));
    return $safe;
}

try {
    cold_phase_assert_frozen(dirname(__DIR__, levels: 2));
    cold_phase_check(substr_count(file_get_contents(__DIR__ . '/cell.php'), 'Subprocess::run(') === 1);
    cold_phase_check(substr_count(file_get_contents(__DIR__ . '/cell.php'), 'timeout: 6') === 1);
    $record = ['schema' => 'tablo/cold-phase-actor/v1', 'status' => 'complete', 'diagnostic' => true,
        'issueAccepted' => false, 'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN',
        'tables' => 16, 'payloadBytes' => 11_520_000, 'nativeBufferedSource' => false, 'allTypedDigestsPreserved' => true,
        'baselinePhpBytes' => 4_000_000, 'peakPhpBytes' => 7_000_000, 'peakPhpWorkingBytes' => 3_000_000,
        'fixturePeakPhpBytes' => 4_100_000, 'sourcePeakPhpBytes' => 4_200_000, 'exportPeakPhpBytes' => 4_300_000,
        'serverImportPeakPhpBytes' => 6_500_000, 'serverAdmissionPeakPhpBytes' => 6_600_000, 'serverVerifyPeakPhpBytes' => 6_700_000,
        'readerAdmissionPeakPhpBytes' => 6_800_000, 'reverseExportPeakPhpBytes' => 6_900_000,
        'sqliteImportPeakPhpBytes' => 6_900_000, 'finalVerifyPeakPhpBytes' => 7_000_000];
    $record['fixtureUsedPhpBytes'] = 4_000_000;
    $record['sourceUsedPhpBytes'] = 4_000_000;
    $record['exportUsedPhpBytes'] = 4_000_000;
    $record['serverImportUsedPhpBytes'] = 4_000_000;
    $record['serverAdmissionUsedPhpBytes'] = 4_000_000;
    $record['serverVerifyUsedPhpBytes'] = 4_000_000;
    $record['readerAdmissionUsedPhpBytes'] = 4_000_000;
    $record['reverseExportUsedPhpBytes'] = 4_000_000;
    $record['sqliteImportUsedPhpBytes'] = 4_000_000;
    $record['finalVerifyUsedPhpBytes'] = 4_000_000;
    $record['nestedPeakPhpBytes'] = $record['peakPhpBytes'];
    $record['nestedCurrentPhpBytes'] = 5_000_000;
    $record['nestedPreviousStep'] = 'ARCHIVE_CONSUMER_BEFORE';
    $record['nestedPreviousPeakPhpBytes'] = 4_000_000;
    $record['nestedStep'] = 'SCHEMA_CHECKS'; $record['nestedTable'] = 'sites'; $record['nestedOuterPhase'] = 'final_verify';
    $transport = ['actorLaunchCount' => 1, 'actorExit' => 0, 'actorClosed' => true, 'actorExitObserved' => true, 'actorElapsedMilliseconds' => 1500];
    $valid = cold_phase_control($record, $transport, accepted: true);
    cold_phase_check($valid['memoryCapExceeded'] === true);
    cold_phase_check($valid['serverImportPeakPhpBytes'] === 6_500_000);
    foreach (array_keys($record) as $field) {
        $missing = $record; unset($missing[$field]); cold_phase_control($missing, $transport, accepted: false);
    }
    foreach (['baselinePhpBytes', 'peakPhpBytes', 'peakPhpWorkingBytes', 'fixturePeakPhpBytes', 'finalVerifyPeakPhpBytes'] as $field) {
        foreach (['PRIVATE_SQL_PATH_SECRET', 1.0, false, null, -1, 0, 1_073_741_825, ['PRIVATE_SQL_PATH_SECRET']] as $bad) {
            $mutant = $record; $mutant[$field] = $bad; cold_phase_control($mutant, $transport, accepted: false);
        }
    }
    foreach (['schema', 'status', 'diagnostic', 'issueAccepted', 'originalActorAccepted', 'rootCause', 'tables', 'payloadBytes', 'nativeBufferedSource', 'allTypedDigestsPreserved'] as $field) {
        $mutant = $record; $mutant[$field] = 'PRIVATE_SQL_PATH_SECRET'; cold_phase_control($mutant, $transport, accepted: false);
    }
    $mutant = $record; $mutant['secret'] = 'PRIVATE_SQL_PATH_SECRET'; cold_phase_control($mutant, $transport, accepted: false);
    $mutant = $record; $mutant['peakPhpWorkingBytes']++; cold_phase_control($mutant, $transport, accepted: false);
    $mutant = $record; $mutant['serverAdmissionPeakPhpBytes'] = 4_000_000; cold_phase_control($mutant, $transport, accepted: false);
    foreach ([['actorLaunchCount', 2], ['actorExit', 1], ['actorExit', '0'], ['actorClosed', false], ['actorExitObserved', false]] as [$field, $bad]) {
        $mutant = $transport; $mutant[$field] = $bad; cold_phase_control($record, $mutant, accepted: false);
    }
    $bytes = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
    foreach ([$bytes . $bytes, ' ' . $bytes, $bytes . "\n", '{"secret":"PRIVATE_SQL_PATH_SECRET",' . substr($bytes, 1),
        str_replace('"tables":16', '"tables":15,"tables":16', $bytes), str_repeat('X', 4097), '{', 'null'] as $bad) {
        $safe = cold_phase_project('mysql', $bad, '', $transport); cold_phase_check($safe['measurementComplete'] === false);
        cold_phase_check(!str_contains(json_encode($safe, JSON_THROW_ON_ERROR), 'PRIVATE_SQL_PATH_SECRET'));
    }
    cold_phase_check(cold_phase_project('mysql', $bytes, 'PRIVATE_SQL_PATH_SECRET', $transport)['measurementComplete'] === false);
    $within = $record;
    foreach (array_keys($within) as $field) {
        if (str_ends_with($field, 'PeakPhpBytes')) { $within[$field] = 4_100_000; }
    }
    $within['peakPhpWorkingBytes'] = 2_097_152; $within['peakPhpBytes'] = $within['baselinePhpBytes'] + 2_097_152; $within['nestedPeakPhpBytes'] = $within['peakPhpBytes'];
    cold_phase_check(cold_phase_control($within, $transport, accepted: true)['memoryCapExceeded'] === false);
    $within['peakPhpWorkingBytes']++; $within['peakPhpBytes']++; $within['nestedPeakPhpBytes'] = $within['peakPhpBytes'];
    cold_phase_check(cold_phase_control($within, $transport, accepted: true)['memoryCapExceeded'] === true);
    cold_phase_check(cold_phase_project('mariadb', $bytes, '', $transport)['engine'] === 'mariadb');
    $failure = cold_phase_empty_receipt('mysql');
    cold_phase_note_failure($failure, 'CELL_SETUP', new ColdPhaseFailure('FROZEN_FILE_MISMATCH'));
    cold_phase_note_failure($failure, 'OWNED_CLEANUP', new RuntimeException('PRIVATE_SQL_PATH_SECRET'));
    cold_phase_check($failure['firstFailureStage'] === 'CELL_SETUP' && $failure['firstFailureCategory'] === 'FROZEN_FILE_MISMATCH');
    cold_phase_check(!str_contains(json_encode($failure), 'PRIVATE_SQL_PATH_SECRET'));
    $exitTransport = $transport; $exitTransport['actorExit'] = 1;
    cold_phase_check(cold_phase_project('mysql', $bytes, 'PRIVATE_SQL_PATH_SECRET', $exitTransport)['firstFailureCategory'] === 'ACTOR_EXIT');
    cold_phase_check(cold_phase_project('mysql', $bytes, 'PRIVATE_SQL_PATH_SECRET', $transport)['firstFailureCategory'] === 'ACTOR_STDERR');
    cold_phase_check(cold_phase_project('mysql', '{', '', $transport)['firstFailureCategory'] === 'ACTOR_RECORD');
    $openTransport = $transport; $openTransport['actorClosed'] = false;
    cold_phase_check(cold_phase_project('mysql', $bytes, '', $openTransport)['firstFailureCategory'] === 'ACTOR_TRANSPORT');
    echo json_encode(['scope' => 'pure synthetic scalar-receipt controls; no actor, database, CI or native acceptance', 'status' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, data: "SYNTHETIC_COLD_PHASE_CONTROLS_FAILED\n");
    exit(1);
}
