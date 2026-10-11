<?php
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$lekalo = getenv('LEKALO_BIN') ?: 'lekalo';

function runGate(array $command): void
{
    fwrite(STDOUT, '> ' . implode(' ', $command) . PHP_EOL);
    fflush(STDOUT);
    // Independent file streams capture both channels without pipe backpressure
    // or unsupported Windows nonblocking APIs. Read only after direct closure.
    $stdout = tmpfile(); $stderr = tmpfile();
    if (!is_resource($stdout) || !is_resource($stderr)) {
        if (is_resource($stdout)) { fclose($stdout); }
        if (is_resource($stderr)) { fclose($stderr); }
        throw new RuntimeException('Cannot reserve gate capture streams');
    }
    $process = null;
    $exit = null;
    try {
        $process = proc_open($command, [0 => STDIN, 1 => $stdout, 2 => $stderr], $pipes,
            options: ['bypass_shell' => true]);
        if (is_resource($process)) {
            $initial = proc_get_status($process);
            $closed = proc_close($process);
            $process = null;
            $exit = !$initial['running'] && $initial['exitcode'] !== -1 ? $initial['exitcode']
                : ($closed !== -1 ? $closed : null);
            foreach ([[$stdout, STDOUT], [$stderr, STDERR]] as [$capture, $output]) {
                if (!rewind($capture) || stream_copy_to_stream($capture, $output) === false) {
                    throw new RuntimeException('Gate stream capture failed');
                }
                fflush($output);
            }
            fwrite(STDOUT, 'Gate pid: ' . $initial['pid'] . '; initial exit: ' . $initial['exitcode'] . '; proc_close: ' . $closed . PHP_EOL);
        }
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        fclose($stdout); fclose($stderr);
    }
    fwrite(STDOUT, 'Gate exit: ' . ($exit === null ? 'not-started' : $exit) . PHP_EOL);
    fflush(STDOUT);
    if ($exit !== 0) {
        throw new RuntimeException('Gate failed: ' . implode(' ', $command));
    }
}

try {
    require __DIR__ . '/reviewed-dependencies.php';
    verifyWorkerDependencies($root); // FIRST: refusal precedes any contract/native subprocess.
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
                'dashboard.change_admin_password' => 'tests/Unit/AdminPasswordTest.php,tests/Network/AdminPasswordTest.php,tests/Network/PasswordHelperTest.php,tests/Http/AdminPasswordTest.php',
                'dashboard.setup' => 'tests/Unit/AuthTest.php,tests/Unit/PasswordServiceTest.php,tests/Unit/PasswordPrivacyTest.php,tests/Unit/AdminPasswordTest.php,tests/Network/AdminPasswordTest.php,tests/Http/AdminPasswordTest.php',
                'dashboard.login' => 'tests/Unit/AuthTest.php,tests/Unit/ClientAddressTest.php,tests/Http/LoginProxyTest.php,tests/Http/AdminPasswordTest.php',
                'dashboard.list_branches' => 'tests/Unit/GitHubProviderTest.php,tests/Unit/GitHubBudgetTest.php,tests/Network/HttpClientTest.php,tests/Network/GitHubSweepTest.php,tests/Http/GitHubBudgetTest.php',
                'dashboard.check_site' => 'tests/Unit/SiteCheckerTest.php,tests/Unit/HealthChecksTest.php,tests/Unit/HealthUrlTest.php,tests/Unit/HealthFailurePolicyTest.php,tests/Unit/ExplicitHealthChecksTest.php,tests/Unit/HealthPersistenceTest.php,tests/Unit/HttpClientTest.php,tests/Unit/JsonChecksTest.php,tests/Network/HttpClientTest.php,tests/Unit/GitHubProviderTest.php,tests/Unit/GitHubBudgetTest.php,tests/Network/GitHubSweepTest.php,tests/Http/GitHubBudgetTest.php,tests/Network/HttpFramingTest.php,tests/Http/HttpFramingTest.php',
                'dashboard.run_checks' => 'tests/Unit/CheckCommandTest.php,tests/Network/CheckCommandTest.php,tests/Unit/SiteCheckerTest.php,tests/Unit/SiteRepositoryTest.php,tests/Unit/GitHubBudgetTest.php,tests/Network/GitHubCorrectionsTest.php,tests/Network/ExternalKeyTest.php,tests/Unit/EnvironmentBootstrapTest.php,tests/Unit/ReviewedDependenciesTest.php,tests/Unit/CurlPlatformTest.php,tests/Network/EndpointTokenTransportTest.php,tests/Network/EndpointRedirectTest.php,tests/Network/EndpointRedirectPrivacyTest.php',
                'dashboard.create_site', 'dashboard.update_site' => 'tests/Unit/SiteRepositoryTest.php,tests/Unit/JsonChecksTest.php,tests/Unit/HealthChecksTest.php,tests/Unit/HealthUrlTest.php,tests/Unit/HealthFailurePolicyTest.php,tests/Unit/ExplicitHealthChecksTest.php,tests/Unit/HealthPersistenceTest.php',
                'dashboard.logout' => 'tests/Http/AdminPasswordTest.php',
                'dashboard.get_settings', 'dashboard.update_settings' => 'tests/Unit/SettingsRepositoryTest.php,tests/Http/SettingsTest.php,tests/Network/WorkerSettingsTest.php',
                'dashboard.run_worker_pass' => 'tests/Unit/PeriodicWorkerTest.php,tests/Network/PeriodicWorkerTest.php,tests/Network/WorkerFairnessTest.php,tests/Unit/GitHubBudgetTest.php,tests/Network/GitHubSweepTest.php,tests/Network/GitHubCorrectionsTest.php,tests/Unit/SiteCheckerTest.php,tests/Http/HealthChecksTest.php,tests/Http/HomepageUrlTest.php,tests/Http/ExplicitHealthPathsTest.php,tests/Http/HealthPartialResultsTest.php,tests/Http/HealthInFlightTest.php,tests/Network/HttpFramingTest.php,tests/Http/HttpFramingTest.php',
                'dashboard.request_worker_stop' => 'tests/Network/PeriodicWorkerTest.php,tests/Unit/PeriodicWorkerTest.php',
                'dashboard.store_check', 'dashboard.settle_worker_check' => 'tests/Unit/CheckHistoryTest.php,tests/Unit/WorkerSettlementTest.php,tests/Network/HistoryConcurrencyTest.php,tests/Network/HistoryDiagnosticsTest.php,tests/Network/PeriodicWorkerTest.php,tests/Http/HealthChecksTest.php',
                'dashboard.read_history', 'dashboard.prune_history' => 'tests/Unit/HistoryReadRetentionTest.php,tests/Network/HistoryConcurrencyTest.php',
                'dashboard.read_incidents' => 'tests/Unit/IncidentReadTest.php,tests/Unit/IncidentLifecycleTest.php,tests/Http/IncidentsTest.php,tests/Http/IncidentQualifiersTest.php',
                'dashboard.get_notifications', 'dashboard.update_notifications' => 'tests/Unit/NotificationSettingsTest.php,tests/Http/NotificationSettingsTest.php',
                'dashboard.project_notifications' => 'tests/Unit/NotificationSelectionTest.php,tests/Unit/NotificationAtomicityTest.php,tests/Unit/NotificationMigrationTest.php,tests/Network/NotificationPublicTest.php',
                'dashboard.read_notification_status' => 'tests/Unit/NotificationOutboxTest.php,tests/Network/NotificationRaceTest.php,tests/Http/NotificationSettingsTest.php',
                'dashboard.claim_notification', 'dashboard.ack_notification' => 'tests/Unit/NotificationOutboxTest.php,tests/Network/NotificationRaceTest.php',
                'dashboard.deliver_notification' => 'tests/Unit/NotificationOutboxTest.php,tests/Network/WebhookSupervisorTest.php,tests/Network/WebhookTransportTest.php,tests/Network/NotificationPublicTest.php,tests/Network/NotificationRaceTest.php',
                default => 'tests/Unit/SiteRepositoryTest.php',
            };
            if (in_array($symbol['id'], ['dashboard.setup', 'dashboard.login', 'dashboard.change_admin_password'], true)) {
                $unitTests .= ',tests/Unit/PasswordServiceTest.php,tests/Unit/PasswordPrivacyTest.php,tests/Unit/AdminPasswordTest.php,tests/Network/AdminPasswordTest.php,tests/Network/PasswordHelperTest.php,tests/Http/AdminPasswordTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.list_branches', 'dashboard.check_site', 'dashboard.create_site', 'dashboard.update_site'], true)) {
                $unitTests .= ',tests/Unit/GitHubCredentialTest.php,tests/Network/GitHubCorrectionsTest.php,tests/Network/GitHubKeyCustodyTest.php,tests/Network/GitHubResponsePrivacyTest.php,tests/Network/GitHubTraceInspectorTest.php';
            }
            if ($symbol['id'] === 'dashboard.run_worker_pass') {
                $unitTests .= ',tests/Unit/WorkerAdmissionTest.php,tests/Unit/WorkerSettlementTest.php,tests/Network/WorkerSharedFailureTest.php,tests/Http/WorkerDashboardTest.php,tests/Network/WorkerSettingsTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.check_site', 'dashboard.run_worker_pass', 'dashboard.create_site', 'dashboard.update_site', 'dashboard.list_branches'], true)) {
                $unitTests .= ',tests/Unit/EndpointRedirectPolicyTest.php,tests/Network/EndpointRedirectTest.php,tests/Network/EndpointRedirectPrivacyTest.php,tests/Http/EndpointRedirectTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.check_site', 'dashboard.run_worker_pass'], true)) {
                $unitTests .= ',tests/Unit/CheckHistoryTest.php,tests/Network/HistoryConcurrencyTest.php,tests/Network/HistoryDiagnosticsTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.store_check', 'dashboard.settle_worker_check', 'dashboard.check_site',
                'dashboard.run_worker_pass', 'dashboard.prune_history', 'dashboard.delete_site'], true)) {
                $unitTests .= ',tests/Unit/IncidentLifecycleTest.php,tests/Unit/IncidentAtomicityTest.php,tests/Network/IncidentConcurrencyTest.php,tests/Network/IncidentPublicTest.php,tests/Http/IncidentsTest.php,tests/Http/IncidentQualifiersTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.store_check', 'dashboard.settle_worker_check', 'dashboard.check_site', 'dashboard.run_worker_pass', 'dashboard.delete_site'], true)) {
                $unitTests .= ',tests/Unit/NotificationSelectionTest.php,tests/Unit/NotificationAtomicityTest.php,tests/Unit/NotificationOutboxTest.php,tests/Network/NotificationPublicTest.php,tests/Network/NotificationRaceTest.php';
            }
            $tests = ($unitTests === '' ? '' : $unitTests . ',') . 'tests/Http/DashboardTest.php';
            if (in_array($symbol['id'], ['dashboard.create_site', 'dashboard.update_site', 'dashboard.check_site', 'dashboard.run_worker_pass', 'dashboard.list_branches'], true)) {
                $tests .= ',tests/Unit/EndpointTokenRepositoryTest.php,tests/Unit/EndpointTokenCheckerTest.php,tests/Unit/EndpointTokenMigrationTest.php,tests/Unit/EndpointTokenIsolationTest.php,tests/Network/EndpointTokenTransportTest.php,tests/Network/EndpointTokenStatusTest.php,tests/Network/EndpointTokenNetworkSafetyTest.php,tests/Network/EndpointTokenPrivacyTest.php,tests/Http/EndpointTokenCrudTest.php,tests/Http/EndpointTokenRunnerTest.php,tests/Http/EndpointTokenInFlightTest.php,tests/Http/EndpointTokenReflectionTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.create_site', 'dashboard.update_site', 'dashboard.check_site'], true)) {
                $tests .= ',tests/Http/JsonChecksTest.php,tests/Http/HealthChecksTest.php,tests/Http/HomepageUrlTest.php,tests/Http/ExplicitHealthPathsTest.php,tests/Http/HealthPartialResultsTest.php,tests/Http/HealthInFlightTest.php';
            }
            // Every HTTP action initializes the database before handling its own command/query.
            $tests .= ',tests/Unit/CurlPlatformTest.php';
            $tests .= ',tests/Unit/DatabaseTest.php,tests/Unit/GitHubMigrationTest.php,tests/Unit/WorkerMigrationTest.php,tests/Network/DatabaseMigrationTest.php,tests/Network/DatabaseWalTest.php,tests/Network/FixtureCleanupTest.php';
            $tests .= ',tests/Unit/DatabaseTest.php,tests/Unit/GitHubMigrationTest.php,tests/Unit/WorkerMigrationTest.php,tests/Unit/HistoryMigrationTest.php,tests/Network/DatabaseMigrationTest.php,tests/Network/DatabaseWalTest.php,tests/Network/FixtureCleanupTest.php';
            $tests .= ',tests/Unit/IncidentMigrationTest.php';
            $tests .= ',tests/Unit/NotificationMigrationTest.php';
            $tests .= ',tests/Unit/CompositionSchemaTest.php,tests/Unit/CompositionSettlementTest.php,tests/Unit/CompositionKeyTest.php';
            $tests .= ',tests/Unit/ExternalKeyTest.php,tests/Unit/ExternalKeyAuthenticationTest.php,tests/Unit/ExternalKeyCustodyTest.php,tests/Unit/WorkerKeySettlementTest.php,tests/Unit/WorkerConnectionOwnershipTest.php,tests/Network/ExternalKeyTest.php,tests/Http/ExternalKeyTest.php';
            runGate([$lekalo, 'contract', 'attach', $symbol['id'], '--native-test', $tests, '--gate', 'native-php-tests']);
        }
    }
    runGate([$lekalo, 'contract', 'check', '--module', 'dashboard', '--no-cache']);
    runGate([PHP_BINARY, 'vendor/bin/testo', 'run']);
    if (!is_dir('artifacts')) { mkdir('artifacts'); }
    file_put_contents('artifacts/verification.json', json_encode([
        'status' => 'passed', 'checked_at' => gmdate('c'), 'lekalo_lock' => hash_file('sha256', 'lekalo.lock'),
        'composer_lock' => hash_file('sha256', 'composer.lock'), 'bindings' => hash_file('sha256', 'contracts/php-bindings.json'),
        'reviewed_dependencies' => hash_file('sha256', 'contracts/reviewed-dependencies.json'),
        'gates' => ['model-validation', 'lock-freshness', 'source-fingerprints', 'contract-conformance', 'native-logic-tests', 'real-http-client-tests', 'isolated-http-flow'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "All gates passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
