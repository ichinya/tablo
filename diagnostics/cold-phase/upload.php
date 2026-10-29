<?php
declare(strict_types=1);
require __DIR__ . '/receipt.php';

// Final CI boundary: reads one authored scalar receipt, never raw actor/controller streams.
try {
    if (count($argv) !== 3 || basename($argv[1]) !== 'safe-cold-phase.json' || is_link($argv[1])) {
        throw new RuntimeException('Exact scalar upload receipt required.');
    }
    $engine = $argv[2]; $empty = cold_phase_empty_receipt($engine);
    $bytes = file_get_contents($argv[1], length: 4097);
    if (!is_string($bytes) || strlen($bytes) > 4096) { throw new RuntimeException('Scalar receipt bound exceeded.'); }
    $record = json_decode($bytes, associative: true, depth: 4, flags: JSON_THROW_ON_ERROR);
    if (!is_array($record) || count($record) !== count($empty) || array_diff(array_keys($record), array_keys($empty)) !== []) {
        throw new RuntimeException('Exact scalar receipt shape required.');
    }
    $mutable = ['status', 'actorLaunchCount', 'actorExit', 'actorClosed', 'actorExitObserved', 'actorElapsedMilliseconds',
        'transportValid', 'measurementComplete', 'memoryCapExceeded', 'sourceCustodyUnchanged', 'ownedServicesClosed', 'observerClosureKnown'];
    foreach ($empty as $name => $value) {
        $actual = $record[$name];
        if (in_array($name, ['firstFailureStage', 'firstFailureCategory'], true)) { continue; }
        if (array_key_exists($name, cold_phase_nested_choices())) {
            if ($actual !== null && (!is_string($actual) || !in_array($actual, cold_phase_nested_choices()[$name], true))) { throw new RuntimeException('Finite nested label required.'); }
            continue;
        }
        if (!is_scalar($actual) && $actual !== null) { throw new RuntimeException('Nested receipt refused.'); }
        if ($value !== null && !in_array($name, $mutable, strict: true) && $actual !== $value) { throw new RuntimeException('Fixed receipt value changed.'); }
        if (is_bool($value) && !is_bool($actual)) { throw new RuntimeException('Boolean receipt fact required.'); }
        if ($value === null && !in_array($name, ['actorClosed', 'actorExitObserved', 'memoryCapExceeded', 'nativeBufferedSource', 'allTypedDigestsPreserved'], strict: true)
            && $actual !== null && (!is_int($actual) || $actual < 0 || $actual > 1_073_741_824)) { throw new RuntimeException('Integer receipt fact required.'); }
    }
    $failurePairs = ['CELL_SETUP' => ['CELL_SETUP_FAILURE', 'FROZEN_DECLARATION', 'FROZEN_PATH_SELECTION', 'FROZEN_FILE_MISMATCH', 'ORIGINAL_ACTOR_DRIFT', 'FIXTURE_DECLARATION', 'FIXTURE_VOLUME'],
        'ACTOR' => ['ACTOR_FAILURE', 'ACTOR_TIMEOUT', 'ACTOR_EXIT', 'ACTOR_STDERR', 'ACTOR_RECORD', 'ACTOR_TRANSPORT'], 'SOURCE_CUSTODY' => ['SOURCE_CUSTODY_FAILURE'],
        'FIXTURE_CLEANUP' => ['FIXTURE_CLEANUP_FAILURE'], 'OWNED_CLEANUP' => ['OWNED_CLEANUP_FAILURE'], 'OBSERVER_CLOSURE' => ['OBSERVER_CLOSURE_FAILURE']];
    if ($record['firstFailureStage'] !== null || $record['firstFailureCategory'] !== null) {
        if (!is_string($record['firstFailureStage']) || !is_string($record['firstFailureCategory'])
            || !in_array($record['firstFailureCategory'], $failurePairs[$record['firstFailureStage']] ?? [], true)) {
            throw new RuntimeException('Finite first failure pair required.');
        }
    }
    foreach (['actorClosed', 'actorExitObserved', 'memoryCapExceeded', 'nativeBufferedSource', 'allTypedDigestsPreserved'] as $name) {
        if ($record[$name] !== null && !is_bool($record[$name])) { throw new RuntimeException('Nullable boolean receipt fact required.'); }
    }
    if (!in_array($record['status'], ['UNKNOWN', 'DIAGNOSTIC_COMPLETE_CAP_EXCEEDED', 'DIAGNOSTIC_COMPLETE_WITHIN_CAP'], strict: true)
        || !in_array($record['actorLaunchCount'], [null, 0, 1], strict: true)) { throw new RuntimeException('Finite diagnostic status required.'); }
    if (($record['actorExit'] !== null && $record['actorExit'] > 255)
        || ($record['actorElapsedMilliseconds'] !== null && $record['actorElapsedMilliseconds'] > 60_000)) {
        throw new RuntimeException('Bounded transport facts required.');
    }
    if ($record['measurementComplete'] === true) {
        $actor = ['schema' => 'tablo/cold-phase-actor/v1', 'status' => 'complete', 'diagnostic' => true, 'issueAccepted' => false,
            'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN'];
        foreach ($record as $name => $value) {
            if (str_ends_with($name, 'PhpBytes') || $name === 'peakPhpWorkingBytes'
                || in_array($name, ['tables', 'payloadBytes', 'nativeBufferedSource', 'allTypedDigestsPreserved', 'nestedStep', 'nestedTable', 'nestedOuterPhase', 'nestedPreviousStep'], strict: true)) { $actor[$name] = $value; }
        }
        $projected = cold_phase_project($engine, json_encode($actor, JSON_THROW_ON_ERROR) . "\n", '', $record);
        foreach (['status', 'transportValid', 'measurementComplete', 'memoryCapExceeded'] as $name) {
            if ($projected[$name] !== $record[$name]) { throw new RuntimeException('Measured scalar integrity refused.'); }
        }
    }
    if ($record['measurementComplete'] === false) {
        foreach ($empty as $name => $value) {
            if ($value === null && !in_array($name, ['actorExit', 'actorClosed', 'actorExitObserved', 'actorElapsedMilliseconds', 'firstFailureStage', 'firstFailureCategory'], strict: true)
                && $record[$name] !== null) { throw new RuntimeException('Incomplete phase fact refused.'); }
        }
        if ($record['status'] !== 'UNKNOWN' || $record['transportValid'] !== false) { throw new RuntimeException('Incomplete status refused.'); }
    }
    echo json_encode($record, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, data: "SAFE_COLD_PHASE_RECEIPT_UNAVAILABLE\n");
    exit(1);
}
