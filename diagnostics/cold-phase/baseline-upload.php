<?php
declare(strict_types=1);

/** Diagnostic-only projection of the original verify controller's private gate receipts. */
function cold_baseline_empty(): array
{
    return ['schema' => 'tablo/cold-baseline-receipt/v1', 'status' => 'NOTRUN_OR_UNKNOWN', 'diagnostic' => true,
        'issueAccepted' => false, 'originalActorAccepted' => false, 'rootCause' => 'UNKNOWN', 'actorLaunchCount' => null,
        'observerPhase' => 'UNKNOWN', 'firstFailureCategory' => null, 'gateExit' => null, 'gateProcessClosed' => null,
        'observerControllerExit' => null];
}

function cold_baseline_directory(string $path): void
{
    if (is_link($path) || !is_dir($path) || str_replace('\\', '/', (string) realpath($path)) !== $path) {
        throw new RuntimeException('Canonical owned diagnostic directory required.');
    }
}

function cold_baseline_bytes(string $path, int $limit): string
{
    cold_baseline_directory(dirname($path));
    if (is_link($path) || !is_file($path) || str_replace('\\', '/', (string) realpath($path)) !== $path) {
        throw new RuntimeException('Regular private diagnostic input required.');
    }
    $bytes = file_get_contents($path, length: $limit + 1);
    if (!is_string($bytes) || strlen($bytes) > $limit) { throw new RuntimeException('Private diagnostic input bound exceeded.'); }
    return $bytes;
}

/** Exact authored command shapes only. No command/path/selector is copied to the result. */
function cold_baseline_command(array $argv, array $plan, string $directory): ?string
{
    if (!array_is_list($argv) || count($argv) > 16) { return null; }
    foreach ($argv as $part) { if (!is_string($part) || strlen($part) > 65_536) { return null; } }
    $php = $plan['php']['path'] ?? null; $lekalo = $plan['lekalo']['path'] ?? null;
    if (!is_string($php) || !is_string($lekalo) || $php === '' || $lekalo === '') { return null; }
    if ($argv === [$php, 'tools/lint.php']) { return 'MAGO_LINT'; }
    if ($argv === [$php, 'tools/analyze.php']) { return 'MAGO_ANALYZE'; }
    if ($argv === [$lekalo, 'validate', '--no-cache']) { return 'LEKALO_VALIDATE'; }
    if ($argv === [$lekalo, 'lock', '--check', '--offline']) { return 'LEKALO_LOCK'; }
    if ($argv === [$lekalo, 'contract', 'update', '--declaration', 'contracts/php-bindings.json']) { return 'CONTRACT_UPDATE'; }
    if ($argv === [$lekalo, 'contract', 'check', '--module', 'dashboard', '--no-cache']) { return 'CONTRACT_CHECK'; }
    if (count($argv) === 8 && array_slice($argv, 0, 3) === [$lekalo, 'contract', 'attach']
        && preg_match('/\Adashboard\.[a-z_]+\z/D', $argv[3]) === 1 && $argv[4] === '--native-test' && $argv[5] !== ''
        && array_slice($argv, 6) === ['--gate', 'native-php-tests']) { return 'CONTRACT_ATTACH'; }
    if ($argv === [$php, 'vendor/bin/testo', 'run', '--path', 'tests/Unit', '--path', 'tests/Network', '--path', 'tests/Http',
        '--json', '--log-junit=' . $directory . '/baseline.xml', '--no-ansi']) { return 'NATIVE_TESTS'; }
    return null;
}

/** Bind the actual direct-child start and close records and apply verify.php's exit selection rule. */
function cold_baseline_gate(array $closed, array $started, array $plan, string $directory): ?array
{
    $initial = $started['initial_status'] ?? null;
    if (!is_array($initial) || !is_int($started['pid'] ?? null) || $started['pid'] < 1
        || ($closed['pid'] ?? null) !== $started['pid'] || ($initial['pid'] ?? null) !== $started['pid']
        || !is_bool($initial['running'] ?? null) || !is_int($initial['exitcode'] ?? null)
        || $initial['exitcode'] < -1 || $initial['exitcode'] > 255
        || !is_array($closed['argv'] ?? null) || ($started['argv'] ?? null) !== $closed['argv']
        || ($started['cwd'] ?? null) !== $plan['source'] || ($closed['cwd'] ?? null) !== $plan['source']
        || !is_int($closed['proc_close'] ?? null) || $closed['proc_close'] < -1 || $closed['proc_close'] > 255
        || !is_int($closed['exit'] ?? null) || $closed['exit'] < 1 || $closed['exit'] > 255) { return null; }
    foreach (['stdout_sha256', 'stderr_sha256'] as $name) {
        if (!is_string($closed[$name] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $closed[$name]) !== 1) { return null; }
    }
    $exit = !$initial['running'] && $initial['exitcode'] !== -1 ? $initial['exitcode']
        : ($closed['proc_close'] !== -1 ? $closed['proc_close'] : null);
    if ($exit !== $closed['exit'] || ($closed['proc_close'] !== -1 && $closed['proc_close'] !== $closed['exit'])) { return null; }
    $category = cold_baseline_command($closed['argv'], $plan, $directory);
    return $category === null ? null : ['category' => $category, 'exit' => $exit];
}

/** Read one positively named cell only. Gate raw stdout/stderr files are never opened. */
function cold_baseline_project(string $cell): array
{
    $safe = cold_baseline_empty();
    cold_baseline_directory($cell);
    $plan = json_decode(cold_baseline_bytes($cell . '/cell.json', 4_194_304), true, 64, JSON_THROW_ON_ERROR);
    $nonce = $plan['nonce'] ?? null;
    if (!is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1
        || ($plan['runtime'] ?? null) !== $cell . '/tablo-server-' . $nonce
        || ($plan['source'] ?? null) !== $plan['runtime'] . '/source'
        || !is_array($plan['observer']['phases'] ?? null)) { throw new RuntimeException('Owned cell required.'); }
    $phases = array_values(array_filter($plan['observer']['phases'], static fn ($phase): bool => is_array($phase) && ($phase['kind'] ?? null) === 'baseline'));
    if (count($phases) !== 1 || !is_string($phases[0]['nonce'] ?? null)
        || preg_match('/\A[0-9a-f]{32}\z/D', $phases[0]['nonce']) !== 1) { throw new RuntimeException('One baseline phase required.'); }
    try {
        // This is only the existing supervisor's <=4096-byte safe failure record, privately redirected by the workflow.
        $observer = json_decode(cold_baseline_bytes($cell . '/private-observer.stderr', 4096), true, 8, JSON_THROW_ON_ERROR);
        if (($observer['schema'] ?? null) === 'tablo/server-safe-observer-failure/v7'
            && in_array($observer['phase'] ?? null, ['prepare', 'baseline', 'engine'], true)) {
            $safe['observerPhase'] = $observer['phase'];
            if (is_int($observer['controllerExit'] ?? null) && $observer['controllerExit'] >= 0 && $observer['controllerExit'] <= 255) {
                $safe['observerControllerExit'] = $observer['controllerExit'];
            }
            if ($observer['phase'] === 'baseline' && $safe['observerControllerExit'] !== null && $safe['observerControllerExit'] !== 0) {
                $safe['status'] = 'BASELINE_CONTROLLER_FAILED'; $safe['firstFailureCategory'] = 'BASELINE_CONTROLLER_FAILURE';
            }
        }
    } catch (Throwable) { /* No raw observer data enters the public record. */ }
    $prefix = $plan['runtime'] . '/tmp/tablo-server-verify-' . substr($phases[0]['nonce'], 0, 16);
    $directory = $prefix . '/composer-verify';
    try { foreach ([$plan['runtime'], $plan['runtime'] . '/tmp', $prefix, $directory] as $path) { cold_baseline_directory($path); } }
    catch (Throwable) { return $safe; }
    $files = glob($directory . '/gate-*-closed.json');
    if (!is_array($files) || count($files) > 128) { return $safe; }
    $failed = [];
    foreach ($files as $file) {
        if (preg_match('/\Agate-[0-9a-f]{16}-closed\.json\z/D', basename($file)) !== 1) { continue; }
        try {
            $closed = json_decode(cold_baseline_bytes($file, 131_072), true, 8, JSON_THROW_ON_ERROR);
            $started = json_decode(cold_baseline_bytes(substr($file, 0, -12) . '-started.json', 131_072), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($closed) || !is_array($started)) { continue; }
            $gate = cold_baseline_gate($closed, $started, $plan, $directory);
            if ($gate !== null) { $failed[] = $gate; }
        } catch (Throwable) { /* Unbound or malformed records cannot invent a failed gate. */ }
    }
    if (count($failed) === 1) {
        $safe['status'] = 'BASELINE_GATE_FAILED'; $safe['firstFailureCategory'] = $failed[0]['category'];
        $safe['gateExit'] = $failed[0]['exit']; $safe['gateProcessClosed'] = true;
    }
    return $safe;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $safe = cold_baseline_empty();
    try {
        if (count($argv) !== 2) { throw new RuntimeException('One owned cell path required.'); }
        $cell = str_replace('\\', '/', $argv[1]);
        if (basename($cell) !== 'tablo-server-cell') { throw new RuntimeException('Owned cell path required.'); }
        $safe = cold_baseline_project($cell);
    } catch (Throwable) { /* Fixed unknown record; never echo an input or exception. */ }
    echo json_encode($safe, JSON_THROW_ON_ERROR), "\n";
}
