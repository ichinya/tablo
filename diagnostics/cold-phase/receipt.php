<?php
declare(strict_types=1);

/** Pure, dependency-free projection. No input-supplied key, path, message or nested value is retained. */
function cold_phase_empty_receipt(string $engine): array
{
    if (!in_array($engine, ['mysql', 'mariadb'], strict: true)) { throw new RuntimeException('Finite diagnostic engine required.'); }
    return ['schema' => 'tablo/cold-phase-receipt/v1', 'status' => 'UNKNOWN', 'diagnostic' => true,
        'issueAccepted' => false, 'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN', 'engine' => $engine,
        'baseHead' => '421042c7cbdc18ad1093b390ac141990062d35e7', 'phpMajor' => 8, 'phpMinor' => 5,
        'actorLaunchCount' => 0, 'actorTimeoutSeconds' => 6, 'wholeCaseTimeoutSeconds' => 8, 'memoryLimitBytes' => 2_097_152,
        'counterCount' => 24, 'baselineResetCount' => 1, 'actorExit' => null, 'actorClosed' => null, 'actorExitObserved' => null,
        'actorElapsedMilliseconds' => null, 'transportValid' => false, 'measurementComplete' => false, 'memoryCapExceeded' => null,
        'baselinePhpBytes' => null, 'peakPhpBytes' => null, 'peakPhpWorkingBytes' => null,
        'fixturePeakPhpBytes' => null, 'fixtureUsedPhpBytes' => null, 'sourcePeakPhpBytes' => null, 'sourceUsedPhpBytes' => null, 'exportPeakPhpBytes' => null, 'exportUsedPhpBytes' => null,
        'serverImportPeakPhpBytes' => null, 'serverImportUsedPhpBytes' => null, 'serverAdmissionPeakPhpBytes' => null, 'serverAdmissionUsedPhpBytes' => null, 'serverVerifyPeakPhpBytes' => null, 'serverVerifyUsedPhpBytes' => null,
        'readerAdmissionPeakPhpBytes' => null, 'readerAdmissionUsedPhpBytes' => null, 'reverseExportPeakPhpBytes' => null, 'reverseExportUsedPhpBytes' => null, 'sqliteImportPeakPhpBytes' => null, 'sqliteImportUsedPhpBytes' => null,
        'finalVerifyPeakPhpBytes' => null, 'finalVerifyUsedPhpBytes' => null, 'tables' => null, 'payloadBytes' => null,
        'nativeBufferedSource' => null, 'allTypedDigestsPreserved' => null,
        'nestedPeakPhpBytes' => null, 'nestedStep' => null, 'nestedTable' => null, 'nestedOuterPhase' => null, 'firstFailureStage' => null, 'firstFailureCategory' => null, 'sourceCustodyUnchanged' => false, 'ownedServicesClosed' => false, 'observerClosureKnown' => false];
}

/** One closed direct actor is required before any child measurement becomes a diagnostic fact. */
function cold_phase_project(string $engine, string $stdout, string $stderr, array $transport): array
{
    $safe = cold_phase_empty_receipt($engine);
    foreach (['actorLaunchCount', 'actorExit', 'actorClosed', 'actorExitObserved', 'actorElapsedMilliseconds'] as $name) {
        $value = $transport[$name] ?? null;
        $valid = match ($name) {
            'actorLaunchCount' => $value === 1,
            'actorExit' => is_int($value) && $value >= 0 && $value <= 255,
            'actorClosed', 'actorExitObserved' => is_bool($value),
            default => is_int($value) && $value >= 0 && $value <= 60_000,
        };
        if ($valid) { $safe[$name] = $value; }
    }
    if (is_int($safe['actorExit']) && $safe['actorExit'] !== 0) { return cold_phase_rejected_actor($safe, 'ACTOR_EXIT'); }
    if ($stderr !== '') { return cold_phase_rejected_actor($safe, 'ACTOR_STDERR'); }
    if ([$safe['actorLaunchCount'], $safe['actorExit'], $safe['actorClosed'], $safe['actorExitObserved']]
        !== [1, 0, true, true]) { return cold_phase_rejected_actor($safe, 'ACTOR_TRANSPORT'); }
    if (strlen($stdout) > 4096) { return cold_phase_rejected_actor($safe, 'ACTOR_RECORD'); }
    $actor = cold_phase_actor_record($stdout);
    if ($actor === null) { return cold_phase_rejected_actor($safe, 'ACTOR_RECORD'); }
    foreach ($actor as $name => $value) {
        if (array_key_exists($name, $safe) && !in_array($name, ['schema', 'status'], strict: true)) { $safe[$name] = $value; }
    }
    $safe['transportValid'] = true; $safe['measurementComplete'] = true;
    $safe['memoryCapExceeded'] = $actor['peakPhpWorkingBytes'] > 2_097_152;
    $safe['status'] = $safe['memoryCapExceeded'] ? 'DIAGNOSTIC_COMPLETE_CAP_EXCEEDED' : 'DIAGNOSTIC_COMPLETE_WITHIN_CAP';
    return $safe;
}

function cold_phase_rejected_actor(array $safe, string $category): array
{
    if (!in_array($category, ['ACTOR_EXIT', 'ACTOR_STDERR', 'ACTOR_TRANSPORT', 'ACTOR_RECORD'], true)) {
        throw new RuntimeException('Finite actor rejection required.');
    }
    $safe['firstFailureStage'] = 'ACTOR'; $safe['firstFailureCategory'] = $category;
    return $safe;
}

/** Canonical, exact flat shape closes duplicate-key, coercion, extra-record and nested-data cases. */
function cold_phase_actor_record(string $stdout): ?array
{
    if (strlen($stdout) > 4096) { return null; }
    try { $actor = json_decode($stdout, associative: true, depth: 4, flags: JSON_THROW_ON_ERROR); }
    catch (JsonException) { return null; }
    if (!is_array($actor) || json_encode($actor, JSON_THROW_ON_ERROR) . "\n" !== $stdout) { return null; }
    $fixed = ['schema' => 'tablo/cold-phase-actor/v1', 'status' => 'complete', 'diagnostic' => true,
        'issueAccepted' => false, 'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN',
        'tables' => 16, 'payloadBytes' => 11_520_000, 'nativeBufferedSource' => false, 'allTypedDigestsPreserved' => true];
    $memory = ['baselinePhpBytes', 'peakPhpBytes', 'peakPhpWorkingBytes', 'fixturePeakPhpBytes', 'sourcePeakPhpBytes',
        'exportPeakPhpBytes', 'serverImportPeakPhpBytes', 'serverAdmissionPeakPhpBytes', 'serverVerifyPeakPhpBytes',
        'readerAdmissionPeakPhpBytes', 'reverseExportPeakPhpBytes', 'sqliteImportPeakPhpBytes', 'finalVerifyPeakPhpBytes', 'fixtureUsedPhpBytes', 'sourceUsedPhpBytes', 'exportUsedPhpBytes', 'serverImportUsedPhpBytes', 'serverAdmissionUsedPhpBytes', 'serverVerifyUsedPhpBytes', 'readerAdmissionUsedPhpBytes', 'reverseExportUsedPhpBytes', 'sqliteImportUsedPhpBytes', 'finalVerifyUsedPhpBytes', 'nestedPeakPhpBytes'];
    if (count($actor) !== count($fixed) + count($memory) + 3 || array_diff(array_keys($actor), [...array_keys($fixed), ...$memory, 'nestedStep', 'nestedTable', 'nestedOuterPhase']) !== []) { return null; }
    foreach ($fixed as $name => $value) { if (($actor[$name] ?? null) !== $value) { return null; } }
    foreach (cold_phase_nested_choices() as $name => $choices) {
        if (!is_string($actor[$name] ?? null) || !in_array($actor[$name], $choices, true)) { return null; }
    }
    if ($actor['nestedPeakPhpBytes'] !== $actor['peakPhpBytes']) { return null; }
    foreach ($memory as $name) {
        $value = $actor[$name] ?? null;
        if (!is_int($value) || $value < 1 || $value > 1_073_741_824) { return null; }
    }
    if ($actor['peakPhpBytes'] - $actor['baselinePhpBytes'] !== $actor['peakPhpWorkingBytes']) { return null; }
    $previous = $actor['baselinePhpBytes'];
    foreach (array_filter(array_slice($memory, offset: 3), static fn (string $name): bool => str_ends_with($name, 'PeakPhpBytes')) as $name) {
        if ($actor[$name] < $previous || $actor[$name] > $actor['peakPhpBytes']) { return null; }
        $previous = $actor[$name];
    }
    foreach (array_filter($memory, static fn (string $name): bool => str_ends_with($name, 'UsedPhpBytes')) as $name) {
        if ($actor[$name] > $actor[str_replace('UsedPhpBytes', 'PeakPhpBytes', $name)]) { return null; }
    }
    return $actor;
}

/** First fixed category wins. Raw exception messages, traces, SQL and paths never enter the receipt. */
function cold_phase_note_failure(array &$safe, string $stage, Throwable $error): void
{
    if ($safe['firstFailureCategory'] !== null) { return; }
    $stages = ['CELL_SETUP', 'ACTOR', 'FIXTURE_CLEANUP', 'SOURCE_CUSTODY', 'OWNED_CLEANUP', 'OBSERVER_CLOSURE'];
    if (!in_array($stage, $stages, true)) { throw new RuntimeException('Finite diagnostic failure stage required.'); }
    $categories = ['FROZEN_DECLARATION', 'FROZEN_PATH_SELECTION', 'FROZEN_FILE_MISMATCH', 'ORIGINAL_ACTOR_DRIFT', 'FIXTURE_DECLARATION', 'FIXTURE_VOLUME'];
    $category = $error instanceof ColdPhaseFailure && in_array($error->category, $categories, true) ? $error->category : $stage . '_FAILURE';
    if ($stage === 'ACTOR' && str_contains($error->getMessage(), '; stage=timeout;')) { $category = 'ACTOR_TIMEOUT'; }
    $safe['firstFailureStage'] = $stage; $safe['firstFailureCategory'] = $category;
}


/** Finite diagnostic labels only; constructed in the controller after actor measurement. */
function cold_phase_nested_choices(): array
{
    return ['nestedStep' => ['OUTER_END_UNWITNESSED', 'TABLE_ENTRY', 'TABLE_ENGINE_AFTER', 'TABLE_COLUMNS_AFTER', 'TABLE_INDEX_AFTER', 'TABLE_FOREIGN_AFTER', 'TABLE_CHECK_AFTER', 'HEADER_ENTRY', 'HEADER_READ', 'HEADER_DECODE', 'HEADER_ENVELOPE_CANONICAL', 'HEADER_PAYLOAD_SHA', 'HEADER_LAYOUT', 'HEADER_COLUMNS_COMPARE', 'HEADER_VALIDATED', 'HEADER_RECEIPTS', 'HEADER_CONTRACT_BEFORE', 'HEADER_CONTRACT_AFTER', 'SCHEMA_ASSERT_ENTRY', 'SCHEMA_ACTUAL_OBJECTS', 'SCHEMA_EXPECTED_TABLE_ENTRY', 'SCHEMA_ACTUAL_COLUMNS', 'SCHEMA_EXPECTED_COLUMNS', 'SCHEMA_COLUMNS_VALIDATE', 'SCHEMA_CHECKS', 'SCHEMA_FOREIGN', 'SCHEMA_INDEX', 'UNKNOWN', 'WIRE_ENTRY', 'WIRE_CELL_AFTER', 'WIRE_EXIT', 'ROWS_FETCH_AFTER', 'ROWS_AFTER_CLOSE_RELEASE_BEFORE_CONSUME', 'CODEC_CONSUME_ENTRY', 'CODEC_JSON_AFTER', 'CODEC_WIRE_CANONICAL_AFTER', 'CODEC_TYPED_AFTER'],
        'nestedTable' => ['NONE', 'tablo_database_state', 'tablo_writer_guard', 'tablo_migration_journal', 'users', 'git_tokens', 'installation_settings', 'worker_runtime', 'github_cooldowns', 'notification_settings', 'read_api_token', 'self_update', 'sites', 'worker_progress', 'check_history', 'incidents', 'incident_checkpoints', 'notification_checkpoints', 'notification_slots', 'login_limits', 'UNKNOWN'],
        'nestedOuterPhase' => ['fixture', 'source', 'sqlite_export', 'server_import', 'writer_admission', 'server_verify', 'reader_admission', 'reverse_export', 'sqlite_import', 'final_verify', 'integrity', 'UNKNOWN']];
}
