<?php
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$lekalo = getenv('LEKALO_BIN') ?: 'lekalo';

function runGate(array $command): void
{
    echo '> ' . implode(' ', $command) . PHP_EOL;
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Gate failed: ' . implode(' ', $command));
    }
}

try {
    runGate([$lekalo, 'validate', '--no-cache']);
    runGate([$lekalo, 'lock', '--check', '--offline']);
    $declaration = json_decode(file_get_contents('contracts/php-bindings.json'), true, 64, JSON_THROW_ON_ERROR);
    foreach ($declaration['symbols'] as $symbol) {
        $path = $symbol['source']['path'];
        if (!is_file($path) || !hash_equals($symbol['fingerprint'], 'sha256:' . hash_file('sha256', $path))) {
            throw new RuntimeException('Unreviewed source drift: ' . $path . '. Review code/model changes and explicitly capture new bindings.');
        }
    }
    runGate([$lekalo, 'contract', 'update', '--declaration', 'contracts/php-bindings.json']);
    foreach ($declaration['symbols'] as $symbol) {
        if (in_array($symbol['kind'], ['command', 'query'], true)) {
            $unitTests = match ($symbol['id']) {
                'dashboard.setup', 'dashboard.login' => 'tests/Unit/AuthTest.php',
                'dashboard.list_branches' => 'tests/Unit/GitHubProviderTest.php',
                'dashboard.check_site' => 'tests/Unit/SiteCheckerTest.php,tests/Unit/HealthChecksTest.php,tests/Unit/HttpClientTest.php,tests/Unit/JsonChecksTest.php,tests/Network/HttpClientTest.php',
                'dashboard.create_site', 'dashboard.update_site' => 'tests/Unit/SiteRepositoryTest.php,tests/Unit/JsonChecksTest.php',
                'dashboard.logout' => '',
                default => 'tests/Unit/SiteRepositoryTest.php',
            };
            $tests = ($unitTests === '' ? '' : $unitTests . ',') . 'tests/Http/DashboardTest.php';
            if (in_array($symbol['id'], ['dashboard.create_site', 'dashboard.update_site', 'dashboard.check_site'], true)) {
                $tests .= ',tests/Http/JsonChecksTest.php,tests/Http/HealthChecksTest.php';
            }
            // Every HTTP action initializes the database before handling its own command/query.
            $tests .= ',tests/Unit/DatabaseTest.php,tests/Network/DatabaseMigrationTest.php';
            runGate([$lekalo, 'contract', 'attach', $symbol['id'], '--native-test', $tests, '--gate', 'native-php-tests']);
        }
    }
    runGate([$lekalo, 'contract', 'check', '--module', 'dashboard', '--no-cache']);
    runGate([PHP_BINARY, 'vendor/bin/testo', 'run']);
    if (!is_dir('artifacts')) { mkdir('artifacts'); }
    file_put_contents('artifacts/verification.json', json_encode([
        'status' => 'passed', 'checked_at' => gmdate('c'), 'lekalo_lock' => hash_file('sha256', 'lekalo.lock'),
        'composer_lock' => hash_file('sha256', 'composer.lock'), 'bindings' => hash_file('sha256', 'contracts/php-bindings.json'),
        'gates' => ['model-validation', 'lock-freshness', 'source-fingerprints', 'contract-conformance', 'native-logic-tests', 'real-http-client-tests', 'isolated-http-flow'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "All gates passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
