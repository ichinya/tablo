<?php
declare(strict_types=1);

/** Pure, dependency-free projection. No input-supplied key, path, message or nested value is retained. */
function cold_phase_empty_receipt(string $engine): array
{
    if (!in_array($engine, ['mysql', 'mariadb'], strict: true)) { throw new RuntimeException('Finite diagnostic engine required.'); }
    return ['schema' => 'tablo/cold-phase-receipt/v1', 'status' => 'UNKNOWN', 'diagnostic' => true,
        'issueAccepted' => false, 'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN', 'engine' => $engine,
        'baseHead' => '5f54eaa7a474f05f8eebe83215e51527956474e4', 'phpMajor' => 8, 'phpMinor' => 5,
        'actorLaunchCount' => 0, 'actorTimeoutSeconds' => 6, 'wholeCaseTimeoutSeconds' => 8, 'memoryLimitBytes' => 2_097_152,
        'counterCount' => 10, 'baselineResetCount' => 1, 'actorExit' => null, 'actorClosed' => null, 'actorExitObserved' => null,
        'actorElapsedMilliseconds' => null, 'transportValid' => false, 'measurementComplete' => false, 'memoryCapExceeded' => null,
        'baselinePhpBytes' => null, 'peakPhpBytes' => null, 'peakPhpWorkingBytes' => null,
        'fixturePeakPhpBytes' => null, 'sourcePeakPhpBytes' => null, 'exportPeakPhpBytes' => null,
        'serverImportPeakPhpBytes' => null, 'serverAdmissionPeakPhpBytes' => null, 'serverVerifyPeakPhpBytes' => null,
        'readerAdmissionPeakPhpBytes' => null, 'reverseExportPeakPhpBytes' => null, 'sqliteImportPeakPhpBytes' => null,
        'finalVerifyPeakPhpBytes' => null, 'tables' => null, 'payloadBytes' => null,
        'nativeBufferedSource' => null, 'allTypedDigestsPreserved' => null,
        'sourceCustodyUnchanged' => false, 'ownedServicesClosed' => false, 'observerClosureKnown' => false];
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
    if ([$safe['actorLaunchCount'], $safe['actorExit'], $safe['actorClosed'], $safe['actorExitObserved']]
        !== [1, 0, true, true] || $stderr !== '' || strlen($stdout) > 4096) { return $safe; }
    $actor = cold_phase_actor_record($stdout);
    if ($actor === null) { return $safe; }
    foreach ($actor as $name => $value) {
        if (array_key_exists($name, $safe) && !in_array($name, ['schema', 'status'], strict: true)) { $safe[$name] = $value; }
    }
    $safe['transportValid'] = true; $safe['measurementComplete'] = true;
    $safe['memoryCapExceeded'] = $actor['peakPhpWorkingBytes'] > 2_097_152;
    $safe['status'] = $safe['memoryCapExceeded'] ? 'DIAGNOSTIC_COMPLETE_CAP_EXCEEDED' : 'DIAGNOSTIC_COMPLETE_WITHIN_CAP';
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
        'readerAdmissionPeakPhpBytes', 'reverseExportPeakPhpBytes', 'sqliteImportPeakPhpBytes', 'finalVerifyPeakPhpBytes'];
    if (count($actor) !== count($fixed) + count($memory) || array_diff(array_keys($actor), [...array_keys($fixed), ...$memory]) !== []) { return null; }
    foreach ($fixed as $name => $value) { if (($actor[$name] ?? null) !== $value) { return null; } }
    foreach ($memory as $name) {
        $value = $actor[$name] ?? null;
        if (!is_int($value) || $value < 1 || $value > 1_073_741_824) { return null; }
    }
    if ($actor['peakPhpBytes'] - $actor['baselinePhpBytes'] !== $actor['peakPhpWorkingBytes']) { return null; }
    $previous = $actor['baselinePhpBytes'];
    foreach (array_slice($memory, offset: 3) as $name) {
        if ($actor[$name] < $previous || $actor[$name] > $actor['peakPhpBytes']) { return null; }
        $previous = $actor[$name];
    }
    return $actor;
}
