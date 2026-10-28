<?php
declare(strict_types=1);

/** Checks only this isolated source tree. It never discovers an installation or reads an .env. */
function cold_phase_assert_frozen(string $root): void
{
    $record = json_decode(file_get_contents(__DIR__ . '/frozen-source.json'), associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
    if (($record['baseHead'] ?? null) !== '5f54eaa7a474f05f8eebe83215e51527956474e4' || !is_array($record['files'] ?? null)) {
        throw new RuntimeException('Frozen diagnostic source declaration refused.');
    }
    foreach ($record['files'] as $name => $digest) {
        if (!is_string($name) || str_contains($name, '..') || str_contains($name, ':') || str_starts_with($name, '/')
            || !is_string($digest) || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1
            || is_link($root . '/' . $name) || !is_file($root . '/' . $name) || !hash_equals($digest, hash_file('sha256', $root . '/' . $name))) {
            throw new RuntimeException('Frozen diagnostic predecessor changed.');
        }
    }
    $original = str_replace("\r\n", "\n", file_get_contents($root . '/tests/server-child.php'));
    $diagnostic = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/actor.php'));
    if (substr_count($diagnostic, 'memory_reset_peak_usage(') !== 1) { throw new RuntimeException('Original single reset changed.'); }
    $actual = cold_phase_measured_region($diagnostic);
    $actual = preg_replace('/^    \$[A-Za-z]+Peak = memory_get_peak_usage\(false\);\n/m', '', $actual);
    $actual = str_replace('cold_phase_unbuffered', 'tablo_engine_transfer_unbuffered', $actual);
    if ($actual !== cold_phase_measured_region($original)) { throw new RuntimeException('Original measured operations or baseline changed.'); }
    if (strpos($diagnostic, '$result = [') < strpos($diagnostic, '$peak = memory_get_peak_usage(false);')) {
        throw new RuntimeException('Diagnostic collection precedes the final peak.');
    }
}

function cold_phase_measured_region(string $source): string
{
    $first = '    $baseline = memory_get_usage(false); memory_reset_peak_usage();';
    $last = '    $peak = memory_get_peak_usage(false); $working = $peak - $baseline;';
    $start = strpos($source, $first); $end = strpos($source, $last);
    if ($start === false || $end === false || $end < $start) { throw new RuntimeException('Exact original measurement boundary absent.'); }
    return substr($source, $start, $end - $start + strlen($last));
}
