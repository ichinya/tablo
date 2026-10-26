<?php
declare(strict_types=1);

namespace Tablo;

use Tablo\ColdTransferCoarsePeak as Coarse;

/** Temporary scalar state, explicitly preloaded before the protected actor baseline. */
final class ColdTransferCoarsePeak
{
    public static int $mode = -1, $phase = 0, $transfer = 0, $factoryParent = 0, $factoryStep = 0, $snapshotStep = 99;
    /** @var array{int,int,int,int,int} Fixed export/import/SQLite/verify/factory counters. */
    public static array $counts = [0, 0, 0, 0, 0];
    /** @var array{int,int,int,int} Fixed peak/used/phase/factory localization. */
    public static array $winner = [0, 0, 0, 0];
    public static bool $broken = false, $written = false;

    public static function setup(): void { self::$mode = getenv('TABLO_TEST_COLD_R81') === 'observe' ? 1 : 0; }
    public static function transfer(int $operation): void { cold_coarse_transfer($operation); }
    public static function factory(int $step): void { cold_coarse_factory($step); }
    public static function snapshot(int $checkpoint): void { cold_coarse_snapshot($checkpoint); }
    public static function layout(int $checkpoint): void { cold_coarse_layout($checkpoint); }
    public static function capture(#[\SensitiveParameter] mixed $value): void { cold_coarse_capture($value); }
}

/** Operation0 closes an interval; the immutable actor has a fixed finite sequence. */
function cold_coarse_transfer(int $operation): void
{
    if (Coarse::$mode !== 1 || Coarse::$broken) { return; }
    cold_coarse_sample();
    if ($operation === 0) {
        if (Coarse::$transfer === 0 || Coarse::$factoryStep !== 0
            || (Coarse::$transfer === 11 && Coarse::$snapshotStep !== 125)) { Coarse::$broken = true; return; }
        Coarse::$transfer = 0; Coarse::$phase = 0; return;
    }
    if (Coarse::$transfer !== 0 || Coarse::$factoryStep !== 0) { Coarse::$broken = true; return; }
    $phase = cold_coarse_transfer_phase($operation);
    if ($phase < 0) { Coarse::$broken = true; return; }
    Coarse::$phase = $phase; Coarse::$transfer = $phase;
}

function cold_coarse_transfer_phase(int $operation): int
{
    $phase = match ($operation) {
        1 => 10 + Coarse::$counts[0]++, 2 => 20 + Coarse::$counts[1]++,
        3 => 30 + Coarse::$counts[2]++, 4 => 40 + Coarse::$counts[3]++, default => -1,
    };
    return $phase < 0 || Coarse::$counts[0] > 2 || Coarse::$counts[1] > 1 || Coarse::$counts[2] > 1 || Coarse::$counts[3] > 2 ? -1 : $phase;
}

/** Checkpoint8 samples return/cleanup and restores the containing transfer interval. */
function cold_coarse_factory(int $step): void
{
    if (Coarse::$mode !== 1 || Coarse::$broken) { return; }
    cold_coarse_sample();
    $factoryCount = Coarse::$counts[4];
    if ($step === 1) {
        if (Coarse::$factoryStep !== 0 || $factoryCount >= 3) { Coarse::$broken = true; return; }
        Coarse::$counts[4] = $factoryCount + 1; Coarse::$factoryParent = Coarse::$phase;
    }
    if ($step !== 1 && (Coarse::$factoryStep === 0 || $step < 2 || $step > 8)) { Coarse::$broken = true; return; }
    if ($step === 8) { Coarse::$phase = Coarse::$factoryParent; Coarse::$factoryStep = 0; return; }
    if ($step !== 1 && $step !== Coarse::$factoryStep + 1) { Coarse::$broken = true; return; }
    Coarse::$factoryStep = $step; Coarse::$phase = 50 + $step;
}

/** Reverse export only: checkpoint100 starts scratch allocation; 101 snapshot, 102 owner, 103 admission,
 * 104..118 archive tables0..14, 119 header capture, 120 header digest, 121 manifest encoding/bounds,
 * 122 quiescence, 123 scratch commit, 124 source commit. Checkpoint125 restores phase11.
 * Sampling always belongs to the prior interval, never an allocation-cause claim. */
function cold_coarse_snapshot(int $checkpoint): void
{
    if (!cold_coarse_snapshot_active($checkpoint)) { return; }
    cold_coarse_sample();
    if ($checkpoint >= 200) { cold_coarse_admission($checkpoint); return; }
    if (Coarse::$factoryStep !== 0 || $checkpoint < 100 || $checkpoint > 125
        || $checkpoint !== Coarse::$snapshotStep + 1) { Coarse::$broken = true; return; }
    Coarse::$snapshotStep = $checkpoint;
    Coarse::$phase = $checkpoint === 125 ? 11 : $checkpoint;
}

/** Other ServerSchema callers remain inert, including the three standalone factories. */
function cold_coarse_snapshot_active(int $checkpoint): bool
{
    if (Coarse::$mode !== 1 || Coarse::$broken || Coarse::$transfer !== 11) { return false; }
    return $checkpoint < 250 || $checkpoint > 252 || (Coarse::$snapshotStep === 103
        && (Coarse::$phase === 201 || Coarse::$phase === 250 || Coarse::$phase === 251));
}

/** Admission200 stream, 201 schema, 202 key, 203..217 footprints0..14, 218 external key, 219 quiescence.
 * Checkpoint220 restores103. Schema250 layout and251 readiness; checkpoint252 restores201. */
function cold_coarse_admission(int $checkpoint): void
{
    if (Coarse::$snapshotStep !== 103 || Coarse::$factoryStep !== 0
        || ($checkpoint > 220 && ($checkpoint < 250 || $checkpoint > 252))) { Coarse::$broken = true; return; }
    $previous = cold_coarse_admission_previous($checkpoint);
    if (Coarse::$phase !== $previous) { Coarse::$broken = true; return; }
    Coarse::$phase = match ($checkpoint) { 220 => 103, 252 => 201, default => $checkpoint };
}

function cold_coarse_admission_previous(int $checkpoint): int
{
    return match ($checkpoint) { 200 => 103, 202, 250 => 201, 252 => 251, default => $checkpoint - 1 };
}

/** Layout300 source projection,301 selection,302 owned observation,303 object admission,
 * 304..321 native tables0..17,322 loop cleanup;323 restores250.
 * Observation350 connection,351 privileges,352 inventory,353 expected names,354 identity/return;
 * 355 restores302. CHECK370..387 tables0..17;388 restores354. */
function cold_coarse_layout(int $checkpoint): void
{
    if (!cold_coarse_layout_active($checkpoint)) { return; }
    cold_coarse_sample();
    $previous = match ($checkpoint) { 300 => 250, 350 => 302, 370 => 354, default => $checkpoint - 1 };
    if (!(($checkpoint >= 300 && $checkpoint <= 323) || ($checkpoint >= 350 && $checkpoint <= 355)
        || ($checkpoint >= 370 && $checkpoint <= 388)) || Coarse::$phase !== $previous) { Coarse::$broken = true; return; }
    Coarse::$phase = match ($checkpoint) { 323 => 250, 355 => 302, 388 => 354, default => $checkpoint };
}

function cold_coarse_layout_active(int $checkpoint): bool
{
    if (Coarse::$mode !== 1 || Coarse::$broken || Coarse::$transfer !== 11
        || Coarse::$snapshotStep !== 103 || Coarse::$factoryStep !== 0) { return false; }
    if (Coarse::$phase !== 250 && (Coarse::$phase < 300 || Coarse::$phase > 387)) { return false; }
    return $checkpoint < 350 || Coarse::$phase === 302 || Coarse::$phase >= 350;
}

function cold_coarse_sample(): void
{
    $peak = \memory_get_peak_usage(false);
    if ($peak <= Coarse::$winner[0]) { return; }
    Coarse::$winner[0] = $peak; Coarse::$winner[2] = Coarse::$phase;
    Coarse::$winner[3] = Coarse::$factoryStep === 0 ? 0 : Coarse::$counts[4];
    Coarse::$winner[1] = \memory_get_usage(false);
}

/** Final capture reads stored actor metrics and never resamples memory. */
function cold_coarse_capture(#[\SensitiveParameter] mixed $value): void
{
    if (Coarse::$mode < 0 || Coarse::$written) { return; }
    $captured = cold_coarse_result($value);
    if ($captured === null) { return; }
    Coarse::$written = true; $known = cold_coarse_complete($captured['peak']);
    cold_coarse_write(['schema' => 'tablo/cold-r81-coarse/v1', 'mode' => Coarse::$mode,
        'instrumentedOnly' => true, 'observerContaminated' => true, 'issueAccepted' => false,
        'phpVersionId' => PHP_VERSION_ID, 'engineId' => match (getenv('TABLO_TEST_DB_DRIVER')) { 'mysql' => 1, 'mariadb' => 2, default => 0 },
        'baselinePhpBytes' => $captured['baseline'], 'peakPhpBytes' => $captured['peak'], 'peakPhpWorkingBytes' => $captured['working'],
        'winnerPeakPhpBytes' => Coarse::$winner[0], 'winnerUsedPhpBytes' => Coarse::$winner[1],
        'winnerPhase' => Coarse::$winner[2], 'winnerFactory' => Coarse::$winner[3],
        'winnerPeakKnown' => $known, 'tailUnknown' => !$known, 'broken' => Coarse::$broken]);
}

/** @return array{baseline: int, peak: int, working: int}|null */
function cold_coarse_result(#[\SensitiveParameter] mixed $value): ?array
{
    if (!is_array($value) || ($value['status'] ?? null) !== 'passed') { return null; }
    if (array_keys($value) !== ['status', 'tables', 'payloadBytes', 'baselinePhpBytes', 'peakPhpBytes', 'peakPhpWorkingBytes',
            'nativeBufferedSource', 'allTypedDigestsPreserved', 'metricScope']
        || ($value['tables'] ?? null) !== 15 || ($value['payloadBytes'] ?? null) !== 11_520_000
        || ($value['nativeBufferedSource'] ?? null) !== false || ($value['allTypedDigestsPreserved'] ?? null) !== true
        || ($value['metricScope'] ?? null) !== 'PHP used-memory peak from after autoload before any fixture/admission/helper warmup through complete roundtrip; external native allocator RSS is not this metric') { return null; }
    return cold_coarse_metrics($value);
}

/** @return array{baseline: int, peak: int, working: int}|null */
function cold_coarse_metrics(#[\SensitiveParameter] array $value): ?array
{
    /** @var mixed $baseline The protected actor's captured scalar still needs a native type check. */
    $baseline = $value['baselinePhpBytes'] ?? null;
    /** @var mixed $peak The protected actor's captured scalar still needs a native type check. */
    $peak = $value['peakPhpBytes'] ?? null;
    /** @var mixed $working The protected actor's captured scalar still needs a native type check. */
    $working = $value['peakPhpWorkingBytes'] ?? null;
    if (!is_int($baseline) || !is_int($peak) || !is_int($working) || $baseline < 0 || $peak < $baseline
        || $peak > 268_435_456 || $working !== $peak - $baseline) { return null; }
    return ['baseline' => $baseline, 'peak' => $peak, 'working' => $working];
}

function cold_coarse_complete(int $peak): bool
{
    return Coarse::$mode === 1 && !Coarse::$broken && Coarse::$transfer === 0 && Coarse::$factoryStep === 0
        && Coarse::$counts[0] === 2 && Coarse::$counts[1] === 1 && Coarse::$counts[2] === 1 && Coarse::$counts[3] === 2
        && Coarse::$counts[4] === 3 && Coarse::$snapshotStep === 125 && Coarse::$winner[0] === $peak;
}

/** Native warnings and exceptions are secondary; output allocation occurs only after actor metric capture. */
function cold_coarse_write(array $record): void
{
    try { NativeWarning::ignore(static function () use ($record): void { cold_coarse_output($record); }); }
    catch (\Throwable $discarded) { unset($discarded); }
}

function cold_coarse_output(array $record): void
{
    $handle = null;
    try {
        $bytes = \json_encode($record, flags: JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 2_048) { return; }
        $root = \Tablo\Tests\Support\EngineSchedule::directory(); $case = $root . '/cases/transfer-working-data';
        if (!is_dir($case) || is_link($root) || is_link($case)) { return; }
        $handle = fopen($case . '/cold-r81.json', mode: 'xb');
        if (!is_resource($handle)) { return; }
        if (!chmod($case . '/cold-r81.json', permissions: 0o600) || fwrite($handle, $bytes) !== strlen($bytes)
            || !fflush($handle) || !fsync($handle)) { return; }
    } finally { if (is_resource($handle)) { fclose($handle); } }
}
