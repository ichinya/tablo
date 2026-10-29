<?php
declare(strict_types=1);

final class ColdPhaseFailure extends RuntimeException
{
    public function __construct(public readonly string $category) { parent::__construct('Diagnostic prerequisite refused.'); }
}

/** Same source selector used by the real cell, before hashing/opening any selected source. */
function cold_phase_selected_names(array $names): array
{
    return \Tablo\Tools\ServerFixturePlan::sourcePaths($names);
}

/** Checks the materialized source; excluded installation/storage paths are never probed. */
function cold_phase_assert_frozen(string $root): void
{
    if (!class_exists(\Tablo\Tools\ServerFixturePlan::class, autoload: false)) { require_once $root . '/tools/ServerFixturePlan.php'; }
    try { $record = json_decode(file_get_contents($root . '/diagnostics/cold-phase/frozen-source.json'), true, 8, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new ColdPhaseFailure('FROZEN_DECLARATION'); }
    if (($record['baseHead'] ?? null) !== '421042c7cbdc18ad1093b390ac141990062d35e7'
        || !is_array($record['files'] ?? null) || !is_array($record['basePaths'] ?? null)
        || ($record['excludedOriginalPaths'] ?? null) !== ['tools/server-cell.php', 'contracts/reviewed-dependencies.json', '.github/workflows/verify.yml', 'app/ServerTableAdmission.php', 'app/DatabaseTransferVerification.php', 'app/SchemaShape.php', 'app/RuntimeIni.php', 'app/DatabaseTransferWire.php', 'app/DatabaseTransferRows.php']) {
        throw new ColdPhaseFailure('FROZEN_DECLARATION');
    }
    $selected = cold_phase_selected_names($record['basePaths']);
    $expected = array_values(array_diff($selected, $record['excludedOriginalPaths']));
    if (array_keys($record['files']) !== $expected || in_array('storage/.gitkeep', $expected, true)) {
        throw new ColdPhaseFailure('FROZEN_PATH_SELECTION');
    }
    foreach ($record['files'] as $name => $digest) {
        if (!is_string($name) || str_contains($name, '..') || str_contains($name, ':') || str_starts_with($name, '/')
            || !is_string($digest) || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1
            || is_link($root . '/' . $name) || !is_file($root . '/' . $name) || !hash_equals($digest, hash_file('sha256', $root . '/' . $name))) {
            throw new ColdPhaseFailure('FROZEN_FILE_MISMATCH');
        }
    }
    $original = str_replace("\r\n", "\n", file_get_contents($root . '/tests/server-child.php'));
    $diagnostic = str_replace("\r\n", "\n", file_get_contents($root . '/diagnostics/cold-phase/actor.php'));
    if (substr_count($diagnostic, 'memory_reset_peak_usage(') !== 1) { throw new ColdPhaseFailure('ORIGINAL_ACTOR_DRIFT'); }
    foreach ($record['instrumentedOriginals'] ?? [] as $name => $digest) {
        $instrumented = str_replace("\r\n", "\n", file_get_contents($root . '/' . $name));
        $stripped = cold_phase_strip_nested($instrumented);
        if (!is_string($digest) || !hash_equals($digest, hash('sha256', $stripped))) { throw new ColdPhaseFailure('ORIGINAL_ACTOR_DRIFT'); }
    }
    if (array_keys($record['instrumentedOriginals'] ?? []) !== ['app/ServerTableAdmission.php', 'app/DatabaseTransferVerification.php', 'app/SchemaShape.php', 'app/RuntimeIni.php', 'app/DatabaseTransferWire.php', 'app/DatabaseTransferRows.php']) { throw new ColdPhaseFailure('FROZEN_DECLARATION'); }
    $actual = cold_phase_strip_nested(cold_phase_measured_region($diagnostic));
    $actual = preg_replace('/^    \$[A-Za-z]+(?:Peak|Used) = memory_get_(?:peak_)?usage\(false\);\n/m', '', $actual);
    $actual = str_replace('cold_phase_unbuffered', 'tablo_engine_transfer_unbuffered', $actual);
    if ($actual !== cold_phase_measured_region($original)
        || strpos($diagnostic, '$result = [') < strpos($diagnostic, '$peak = memory_get_peak_usage(false);')) {
        throw new ColdPhaseFailure('ORIGINAL_ACTOR_DRIFT');
    }
}

function cold_phase_measured_region(string $source): string
{
    $first = '    $baseline = memory_get_usage(false); memory_reset_peak_usage();';
    $last = '    $peak = memory_get_peak_usage(false); $working = $peak - $baseline;';
    $start = strpos($source, $first); $end = strpos($source, $last);
    if ($start === false || $end === false || $end < $start) { throw new ColdPhaseFailure('ORIGINAL_ACTOR_DRIFT'); }
    return substr($source, $start, $end - $start + strlen($last));
}

/** Only explicitly marked scalar probes are stripped; all original bytes must then match frozen421. */
function cold_phase_strip_nested(string $source): string
{
    return preg_replace('/^ *\/\/ COLD_NESTED_BEGIN ([0-9]+)\n.*?^ *\/\/ COLD_NESTED_END \\1\n/ms', '', $source);
}