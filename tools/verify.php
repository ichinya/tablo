<?php
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$selectedLekalo = getenv('LEKALO_BIN');
$lekalo = $selectedLekalo ? $selectedLekalo : 'lekalo';

/** @return resource|false */
function gate_capture_stream(?string $prefix, string $channel): mixed
{
    return $prefix === null ? tmpfile() : fopen($prefix . '.' . $channel, mode: 'x+b');
}

function record_gate_state(?string $prefix, string $phase, array $state): void
{
    if ($prefix === null) { return; }
    if ($phase === 'closed') {
        $state['stdout_sha256'] = hash_file('sha256', $prefix . '.stdout');
        $state['stderr_sha256'] = hash_file('sha256', $prefix . '.stderr');
    }
    file_put_contents($prefix . '-' . $phase . '.json', json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

/** @param list<string> $command */
function run_gate(array $command): void
{
    fwrite(STDOUT, '> ' . implode(' ', $command) . PHP_EOL);
    fflush(STDOUT);
    // Independent file streams capture both channels without pipe backpressure
    // or unsupported Windows nonblocking APIs. Read only after direct closure.
    $captureDirectory = getenv('TABLO_VERIFY_CAPTURE_DIRECTORY');
    $capturePrefix = $captureDirectory ? $captureDirectory . '/gate-' . bin2hex(random_bytes(8)) : null;
    $stdout = gate_capture_stream($capturePrefix, channel: 'stdout');
    $stderr = gate_capture_stream($capturePrefix, channel: 'stderr');
    if (!is_resource($stdout) || !is_resource($stderr)) {
        if (is_resource($stdout)) { fclose($stdout); }
        if (is_resource($stderr)) { fclose($stderr); }
        throw new RuntimeException('Cannot reserve gate capture streams');
    }
    $process = null;
    $exit = null;
    $pipes = [];
    try {
        $process = proc_open($command, [0 => STDIN, 1 => $stdout, 2 => $stderr], $pipes,
            options: ['bypass_shell' => true]);
        if (is_resource($process)) {
            $initial = proc_get_status($process);
            fwrite(STDOUT, 'Gate started pid: ' . $initial['pid'] . PHP_EOL);
            fflush(STDOUT);
            record_gate_state($capturePrefix, phase: 'started', state: [
                'pid' => $initial['pid'], 'argv' => $command, 'cwd' => getcwd(),
                'started_at' => gmdate('c'), 'initial_status' => $initial,
            ]);
            $closed = proc_close($process);
            $process = null;
            $exit = !$initial['running'] && $initial['exitcode'] !== -1 ? $initial['exitcode']
                : (match (true) { ($closed !== -1) => $closed, default => null });
            record_gate_state($capturePrefix, phase: 'closed', state: [
                'pid' => $initial['pid'], 'argv' => $command, 'cwd' => getcwd(),
                'closed_at' => gmdate('c'), 'proc_close' => $closed, 'exit' => $exit,
            ]);
            foreach ([[$stdout, STDOUT], [$stderr, STDERR]] as [$capture, $output]) {
                if (!rewind($capture) || stream_copy_to_stream($capture, $output) === false) {
                    throw new RuntimeException('Gate stream capture failed');
                }
                fflush($output);
            }
            fwrite(STDOUT, 'Gate pid: ' . $initial['pid'] . '; initial exit: ' . $initial['exitcode'] . '; proc_close: ' . $closed . PHP_EOL);
        }
    } finally {
        Tablo\Tools\MagoProcessClosure::close($process);
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
    verify_worker_dependencies($root); // FIRST: refusal precedes any contract/native subprocess.
    if (count($argv) !== 1) { throw new RuntimeException('Verify accepts no extra arguments.'); }
    require __DIR__ . '/NativeTestAttachments.php';
    require __DIR__ . '/BindingsDeclaration.php';
    require_once __DIR__ . '/MagoProcessClosure.php';
    $nativeMap = [];
    run_gate([PHP_BINARY, 'tools/lint.php']);
    run_gate([PHP_BINARY, 'tools/analyze.php']);
    run_gate([$lekalo, 'validate', '--no-cache']);
    run_gate([$lekalo, 'lock', '--check', '--offline']);
    $declaration = Tablo\Tools\BindingsDeclaration::read('contracts/php-bindings.json');
    foreach ($declaration['symbols'] as $symbol) {
        $path = $symbol['source']['path'];
        if (!is_file($path) || !hash_equals($symbol['fingerprint'], 'sha256:' . Tablo\RequiredFile::digest($path))) {
            throw new RuntimeException('Unreviewed source drift: ' . $path . '. Review code/model changes and explicitly capture new bindings.');
        }
    }
    run_gate([$lekalo, 'contract', 'update', '--declaration', 'contracts/php-bindings.json']);
    foreach ($declaration['symbols'] as $symbol) {
        if (!(in_array($symbol['kind'], ['command', 'query'], strict: true))) { continue; }

            $unitTests = match ($symbol['id']) {
                'dashboard.read_api_sites', 'dashboard.read_api_site', 'dashboard.authenticate_read_api' => 'tests/Unit/ReadApiRequestTest.php,tests/Unit/ReadApiRepositoryTest.php,tests/Unit/ApiSiteDtoTest.php,tests/Unit/ReadApiSchemaTest.php,tests/Http/ReadApiHttpTest.php,tests/Http/ReadApiAuthenticationTest.php,tests/Http/ReadApiParametersTest.php,tests/Http/ReadApiPrivacyTest.php,tests/Http/ReadApiSessionContractTest.php,tests/Network/ReadApiBoundaryTest.php',
                'dashboard.read_api_token_metadata', 'dashboard.rotate_read_api_token', 'dashboard.revoke_read_api_token' => 'tests/Unit/ReadApiTokenTest.php,tests/Unit/ReadApiTokenMigrationTest.php,tests/Unit/ReadApiSchemaTest.php,tests/Http/ReadApiTokenManagementTest.php,tests/Http/ReadApiSessionContractTest.php,tests/Network/ReadApiBoundaryTest.php',
                'dashboard.change_admin_password' => 'tests/Unit/AdminPasswordTest.php,tests/Network/AdminPasswordTest.php,tests/Network/AdminPasswordSelectionTest.php,tests/Network/AdminPasswordStorageTest.php,tests/Network/AdminPasswordConflictTest.php,tests/Network/PasswordHelperTest.php,tests/Http/AdminPasswordTest.php,tests/Http/PasswordSessionAdmissionTest.php,tests/Http/PasswordLoginRaceTest.php,tests/Http/PasswordCommitVisibilityTest.php',
                'dashboard.setup' => 'tests/Unit/AuthTest.php,tests/Unit/PasswordServiceTest.php,tests/Unit/PasswordPrivacyTest.php,tests/Unit/AdminPasswordTest.php,tests/Network/AdminPasswordTest.php,tests/Network/AdminPasswordSelectionTest.php,tests/Network/AdminPasswordStorageTest.php,tests/Network/AdminPasswordConflictTest.php,tests/Http/AdminPasswordTest.php,tests/Http/PasswordSessionAdmissionTest.php,tests/Http/PasswordLoginRaceTest.php,tests/Http/PasswordCommitVisibilityTest.php',
                'dashboard.login' => 'tests/Unit/AuthTest.php,tests/Unit/ClientAddressTest.php,tests/Unit/ClientAddressAdmissionTest.php,tests/Unit/ClientAddressStartupTest.php,tests/Http/LoginProxyTest.php,tests/Http/LoginProxyBoundaryTest.php,tests/Http/LoginProxyStartupTest.php,tests/Http/AdminPasswordTest.php,tests/Http/PasswordSessionAdmissionTest.php,tests/Http/PasswordLoginRaceTest.php,tests/Http/PasswordCommitVisibilityTest.php',
                'dashboard.list_branches' => 'tests/Unit/GitHubProviderTest.php,tests/Unit/GitHubBudgetTest.php,tests/Unit/GitHubCooldownPersistenceTest.php,tests/Unit/GitHubRequestBoundsTest.php,tests/Unit/GitHubMemoRotationTest.php,tests/Network/HttpClientTest.php,tests/Network/GitHubSweepTest.php,tests/Http/GitHubBudgetTest.php',
                'dashboard.check_site' => 'tests/Unit/SiteCheckerTest.php,tests/Unit/HealthFailureEvidenceTest.php,tests/Unit/VersionFailureEvidenceTest.php,tests/Unit/HealthChecksTest.php,tests/Unit/HealthUrlTest.php,tests/Unit/HealthFailurePolicyTest.php,tests/Unit/ExplicitHealthChecksTest.php,tests/Unit/HealthPersistenceTest.php,tests/Unit/HttpClientTest.php,tests/Unit/JsonChecksTest.php,tests/Unit/JsonObservationTest.php,tests/Unit/JsonConfigurationTest.php,tests/Network/HttpClientTest.php,tests/Unit/GitHubProviderTest.php,tests/Unit/GitHubBudgetTest.php,tests/Unit/GitHubCooldownPersistenceTest.php,tests/Unit/GitHubRequestBoundsTest.php,tests/Unit/GitHubMemoRotationTest.php,tests/Network/GitHubSweepTest.php,tests/Http/GitHubBudgetTest.php,tests/Network/HttpFramingTest.php,tests/Http/HttpFramingTest.php',
                'dashboard.run_checks' => 'tests/Unit/CheckCommandTest.php,tests/Network/CheckCommandTest.php,tests/Network/CheckCommandStderrTest.php,tests/Network/CheckCommandCasTest.php,tests/Network/CheckCommandAbortTest.php,tests/Network/CheckCommandKeyAdmissionTest.php,tests/Network/CheckCommandInputTest.php,tests/Unit/SiteCheckerTest.php,tests/Unit/HealthFailureEvidenceTest.php,tests/Unit/VersionFailureEvidenceTest.php,tests/Unit/SiteRepositoryTest.php,tests/Unit/SiteCredentialCrudTest.php,tests/Unit/SiteTokenRotationTest.php,tests/Unit/SiteHistoricalCrudTest.php,tests/Unit/GitHubBudgetTest.php,tests/Unit/GitHubCooldownPersistenceTest.php,tests/Unit/GitHubRequestBoundsTest.php,tests/Unit/GitHubMemoRotationTest.php,tests/Network/GitHubCorrectionsTest.php,tests/Network/GitHubEquivalentScopeTest.php,tests/Network/GitHubResponseAdmissionTest.php,tests/Network/ExternalKeyTest.php,tests/Unit/EnvironmentBootstrapTest.php,tests/Unit/ReviewedDependenciesTest.php,tests/Unit/CurlPlatformTest.php,tests/Network/EndpointTokenTransportTest.php,tests/Network/EndpointRedirectTest.php,tests/Network/RedirectRequestLineTest.php,tests/Network/RedirectAdmissionTest.php,tests/Network/RedirectOriginAdmissionTest.php,tests/Network/RedirectBudgetTest.php,tests/Network/EndpointRedirectPrivacyTest.php',
                'dashboard.create_site', 'dashboard.update_site' => 'tests/Unit/SiteRepositoryTest.php,tests/Unit/SiteCredentialCrudTest.php,tests/Unit/SiteTokenRotationTest.php,tests/Unit/SiteHistoricalCrudTest.php,tests/Unit/JsonChecksTest.php,tests/Unit/JsonObservationTest.php,tests/Unit/JsonConfigurationTest.php,tests/Unit/HealthChecksTest.php,tests/Unit/HealthUrlTest.php,tests/Unit/HealthFailurePolicyTest.php,tests/Unit/ExplicitHealthChecksTest.php,tests/Unit/HealthPersistenceTest.php',
                'dashboard.logout' => 'tests/Http/AdminPasswordTest.php,tests/Http/PasswordSessionAdmissionTest.php,tests/Http/PasswordLoginRaceTest.php,tests/Http/PasswordCommitVisibilityTest.php',
                'dashboard.get_settings', 'dashboard.update_settings' => 'tests/Unit/SettingsRepositoryTest.php,tests/Http/SettingsTest.php,tests/Network/WorkerSettingsTest.php',
                'dashboard.get_idle_timeout', 'dashboard.update_idle_timeout' => 'tests/Unit/SessionLifetimeTest.php,tests/Unit/SessionSettingsTest.php,tests/Unit/SessionSettingsMigrationTest.php,tests/Http/SessionIdleTimeoutTest.php,tests/Http/SessionTimestampAdmissionTest.php,tests/Http/SessionPolicyAdoptionTest.php,tests/Http/SessionActionAdmissionTest.php,tests/Http/SessionNetworkRaceTest.php,tests/Http/SessionCookieRaceTest.php,tests/Network/SessionAutomaticTest.php',
                'dashboard.run_worker_pass' => 'tests/Unit/PeriodicWorkerTest.php,tests/Network/PeriodicWorkerTest.php,tests/Network/WorkerAbaAdmissionTest.php,tests/Network/WorkerProcessCustodyTest.php,tests/Network/WorkerStartupAdmissionTest.php,tests/Network/WorkerFairnessTest.php,tests/Network/WorkerSearchDebtTest.php,tests/Network/WorkerBudgetDebtTest.php,tests/Unit/GitHubBudgetTest.php,tests/Unit/GitHubCooldownPersistenceTest.php,tests/Unit/GitHubRequestBoundsTest.php,tests/Unit/GitHubMemoRotationTest.php,tests/Network/GitHubSweepTest.php,tests/Network/GitHubCorrectionsTest.php,tests/Network/GitHubEquivalentScopeTest.php,tests/Network/GitHubResponseAdmissionTest.php,tests/Unit/SiteCheckerTest.php,tests/Unit/HealthFailureEvidenceTest.php,tests/Unit/VersionFailureEvidenceTest.php,tests/Http/HealthChecksTest.php,tests/Http/HomepageUrlTest.php,tests/Http/ExplicitHealthPathsTest.php,tests/Http/HealthPartialResultsTest.php,tests/Http/HealthInFlightTest.php,tests/Network/HttpFramingTest.php,tests/Http/HttpFramingTest.php',
                'dashboard.request_worker_stop' => 'tests/Network/PeriodicWorkerTest.php,tests/Network/WorkerAbaAdmissionTest.php,tests/Network/WorkerProcessCustodyTest.php,tests/Network/WorkerStartupAdmissionTest.php,tests/Unit/PeriodicWorkerTest.php',
                'dashboard.store_check', 'dashboard.settle_worker_check' => 'tests/Unit/CheckHistoryTest.php,tests/Unit/WorkerSettlementTest.php,tests/Network/HistoryConcurrencyTest.php,tests/Network/HistoryDiagnosticsTest.php,tests/Network/PeriodicWorkerTest.php,tests/Network/WorkerAbaAdmissionTest.php,tests/Network/WorkerProcessCustodyTest.php,tests/Network/WorkerStartupAdmissionTest.php,tests/Http/HealthChecksTest.php',
                'dashboard.read_history', 'dashboard.prune_history' => 'tests/Unit/HistoryReadRetentionTest.php,tests/Network/HistoryConcurrencyTest.php',
                'dashboard.read_incidents' => 'tests/Unit/IncidentReadTest.php,tests/Unit/IncidentLifecycleTest.php,tests/Http/IncidentsTest.php,tests/Http/IncidentQualifiersTest.php',
                'dashboard.get_notifications', 'dashboard.update_notifications' => 'tests/Unit/NotificationSettingsTest.php,tests/Unit/NotificationMetadataTest.php,tests/Unit/NotificationMetadataPrivacyTest.php,tests/Unit/NotificationMetadataAtomicityTest.php,tests/Unit/NotificationCredentialTest.php,tests/Http/NotificationSettingsTest.php',
                'dashboard.project_notifications' => 'tests/Unit/NotificationSelectionTest.php,tests/Unit/NotificationAtomicityTest.php,tests/Unit/NotificationMigrationTest.php,tests/Network/NotificationPublicTest.php',
                'dashboard.read_notification_status' => 'tests/Unit/NotificationOutboxTest.php,tests/Network/NotificationRaceTest.php,tests/Http/NotificationSettingsTest.php',
                'dashboard.claim_notification', 'dashboard.ack_notification' => 'tests/Unit/NotificationOutboxTest.php,tests/Network/NotificationRaceTest.php',
                'dashboard.deliver_notification' => 'tests/Unit/NotificationOutboxTest.php,tests/Network/WebhookSupervisorTest.php,tests/Network/WebhookTransportTest.php,tests/Network/NotificationPublicTest.php,tests/Network/NotificationRaceTest.php',
                default => 'tests/Unit/SiteRepositoryTest.php,tests/Unit/SiteCredentialCrudTest.php,tests/Unit/SiteTokenRotationTest.php,tests/Unit/SiteHistoricalCrudTest.php',
            };
            if (in_array($symbol['id'], ['dashboard.setup', 'dashboard.login', 'dashboard.change_admin_password'], strict: true)) {
                $unitTests .= ',tests/Network/PasswordHelperDiagnosticsTest.php,tests/Network/PasswordHelperRetentionTest.php,tests/Network/PasswordHelperFixtureObservationTest.php';
                $unitTests .= ',tests/Unit/PasswordServiceTest.php,tests/Unit/PasswordPrivacyTest.php,tests/Unit/AdminPasswordTest.php,tests/Network/AdminPasswordTest.php,tests/Network/AdminPasswordSelectionTest.php,tests/Network/AdminPasswordStorageTest.php,tests/Network/AdminPasswordConflictTest.php,tests/Network/PasswordHelperTest.php,tests/Http/AdminPasswordTest.php,tests/Http/PasswordSessionAdmissionTest.php,tests/Http/PasswordLoginRaceTest.php,tests/Http/PasswordCommitVisibilityTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.list_branches', 'dashboard.check_site', 'dashboard.create_site', 'dashboard.update_site'], strict: true)) {
                $unitTests .= ',tests/Unit/GitHubCredentialTest.php,tests/Network/GitHubCorrectionsTest.php,tests/Network/GitHubEquivalentScopeTest.php,tests/Network/GitHubResponseAdmissionTest.php,tests/Network/GitHubKeyCustodyTest.php,tests/Network/GitHubKeyLivenessTest.php,tests/Network/GitHubKeyTraceTest.php,tests/Network/GitHubResponsePrivacyTest.php,tests/Network/GitHubClassifierPrivacyTest.php,tests/Network/GitHubTraceInspectorTest.php';
            }
            if ($symbol['id'] === 'dashboard.run_worker_pass') {
                $unitTests .= ',tests/Unit/WorkerAdmissionTest.php,tests/Unit/WorkerSettlementTest.php,tests/Network/WorkerSharedFailureTest.php,tests/Network/WorkerKeyFailureTest.php,tests/Network/WorkerMetricFailureTest.php,tests/Http/WorkerDashboardTest.php,tests/Network/WorkerSettingsTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.check_site', 'dashboard.run_worker_pass', 'dashboard.create_site', 'dashboard.update_site', 'dashboard.list_branches'], strict: true)) {
                $unitTests .= ',tests/Unit/EndpointRedirectPolicyTest.php,tests/Unit/EndpointAddressPolicyTest.php,tests/Unit/EndpointRoutingPermissionTest.php,tests/Network/EndpointRedirectTest.php,tests/Network/RedirectRequestLineTest.php,tests/Network/RedirectAdmissionTest.php,tests/Network/RedirectOriginAdmissionTest.php,tests/Network/RedirectBudgetTest.php,tests/Network/EndpointRedirectPrivacyTest.php,tests/Http/EndpointRedirectTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.check_site', 'dashboard.run_worker_pass'], strict: true)) {
                $unitTests .= ',tests/Unit/CheckHistoryTest.php,tests/Network/HistoryConcurrencyTest.php,tests/Network/HistoryDiagnosticsTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.store_check', 'dashboard.settle_worker_check', 'dashboard.check_site',
                'dashboard.run_worker_pass', 'dashboard.prune_history', 'dashboard.delete_site'], strict: true)) {
                $unitTests .= ',tests/Unit/IncidentLifecycleTest.php,tests/Unit/IncidentAtomicityTest.php,tests/Network/IncidentConcurrencyTest.php,tests/Network/IncidentPublicTest.php,tests/Http/IncidentsTest.php,tests/Http/IncidentQualifiersTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.store_check', 'dashboard.settle_worker_check', 'dashboard.check_site', 'dashboard.run_worker_pass', 'dashboard.delete_site'], strict: true)) {
                $unitTests .= ',tests/Unit/NotificationSelectionTest.php,tests/Unit/NotificationAtomicityTest.php,tests/Unit/NotificationOutboxTest.php,tests/Network/NotificationPublicTest.php,tests/Network/NotificationRaceTest.php';
            }
            $tests = ($unitTests === '' ? '' : $unitTests . ',') . 'tests/Http/DashboardTest.php,tests/Http/SiteFormAdmissionTest.php,tests/Http/AuthenticationSetupTest.php,tests/Http/AuthenticationLifecycleTest.php,tests/Http/SiteCredentialPageTest.php,tests/Http/SavedTokenPageTest.php';
            if (in_array($symbol['id'], ['dashboard.create_site', 'dashboard.update_site', 'dashboard.check_site', 'dashboard.run_worker_pass', 'dashboard.list_branches'], strict: true)) {
                $tests .= ',tests/Unit/EndpointTokenRepositoryTest.php,tests/Unit/EndpointTokenCheckerTest.php,tests/Unit/EndpointTokenMigrationTest.php,tests/Unit/EndpointTokenIsolationTest.php,tests/Network/EndpointTokenTransportTest.php,tests/Network/EndpointTokenStatusTest.php,tests/Network/EndpointTokenNetworkSafetyTest.php,tests/Network/EndpointTokenPrivacyTest.php,tests/Http/EndpointTokenCrudTest.php,tests/Http/EndpointTokenRunnerTest.php,tests/Http/EndpointTokenInFlightTest.php,tests/Http/EndpointTokenReflectionTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.create_site', 'dashboard.update_site', 'dashboard.check_site'], strict: true)) {
                $tests .= ',tests/Http/JsonChecksTest.php,tests/Http/HealthChecksTest.php,tests/Http/HomepageUrlTest.php,tests/Http/ExplicitHealthPathsTest.php,tests/Http/HealthPartialResultsTest.php,tests/Http/HealthInFlightTest.php';
            }
            // Preserve the original browser/database compatibility coverage; bearer ingress has its own key-free boundary tests above.
            $tests .= ',tests/Unit/CurlPlatformTest.php';
            $tests .= ',tests/Unit/DatabaseTest.php,tests/Unit/DatabaseHistoricalImportTest.php,tests/Unit/DatabaseDiagnosticsUpgradeTest.php,tests/Unit/DatabaseSchemaAdmissionTest.php,tests/Unit/DatabaseMigrationAtomicityTest.php,tests/Unit/DatabaseWalNegotiationTest.php,tests/Unit/DatabaseWalCleanupTest.php,tests/Unit/DatabaseWalClockTest.php,tests/Unit/GitHubMigrationTest.php,tests/Unit/WorkerMigrationTest.php,tests/Network/DatabaseMigrationTest.php,tests/Network/DatabaseMigrationLockTest.php,tests/Network/DatabaseMigrationCustodyTest.php,tests/Network/DatabaseWalTest.php,tests/Network/DatabaseWalHolderTest.php,tests/Network/FixtureCleanupTest.php';
            $tests .= ',tests/Unit/DatabaseTest.php,tests/Unit/DatabaseHistoricalImportTest.php,tests/Unit/DatabaseDiagnosticsUpgradeTest.php,tests/Unit/DatabaseSchemaAdmissionTest.php,tests/Unit/DatabaseMigrationAtomicityTest.php,tests/Unit/DatabaseWalNegotiationTest.php,tests/Unit/DatabaseWalCleanupTest.php,tests/Unit/DatabaseWalClockTest.php,tests/Unit/GitHubMigrationTest.php,tests/Unit/WorkerMigrationTest.php,tests/Unit/HistoryMigrationTest.php,tests/Network/DatabaseMigrationTest.php,tests/Network/DatabaseMigrationLockTest.php,tests/Network/DatabaseMigrationCustodyTest.php,tests/Network/DatabaseWalTest.php,tests/Network/DatabaseWalHolderTest.php,tests/Network/FixtureCleanupTest.php';
            $tests .= ',tests/Unit/IncidentMigrationTest.php';
            $tests .= ',tests/Unit/NotificationMigrationTest.php';
            $tests .= ',tests/Unit/CompositionSchemaTest.php,tests/Unit/CompositionIdentityTest.php,tests/Unit/CompositionAdmissionTest.php,tests/Unit/CompositionMigrationAtomicityTest.php,tests/Unit/CompositionSettlementTest.php,tests/Unit/CompositionKeyTest.php';
            $tests .= ',tests/Unit/SqlSchemaAdmissionTest.php,tests/Unit/SqlSchemaSettlementTest.php';
            $tests .= ',tests/Unit/ExternalKeyTest.php,tests/Unit/ExternalKeyAuthenticationTest.php,tests/Unit/ExternalKeyCustodyTest.php,tests/Unit/WorkerKeySettlementTest.php,tests/Unit/WorkerConnectionOwnershipTest.php,tests/Network/ExternalKeyTest.php,tests/Http/ExternalKeyTest.php';
            $tests .= ',tests/Unit/SchemaObservationRaceTest.php';
            $tests .= ',tests/Unit/DatabasePathTest.php';
            // Every database entry point uses checked PDO acquisition and actual row admission.
            $tests .= ',tests/Unit/SqlStatementTest.php,tests/Unit/SqlRowTest.php';
            if (in_array($symbol['id'], ['dashboard.ack_notification', 'dashboard.deliver_notification'], strict: true)) {
                $tests .= ',tests/Unit/NotificationResultTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.run_checks', 'dashboard.setup', 'dashboard.login',
                'dashboard.change_admin_password', 'dashboard.run_worker_pass'], strict: true)) {
                $tests .= ',tests/Unit/RuntimeIniTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.check_site', 'dashboard.create_site', 'dashboard.update_site',
                'dashboard.run_checks', 'dashboard.run_worker_pass'], strict: true)) {
                $tests .= ',tests/Unit/JsonNumericTest.php';
            }
            if ($symbol['id'] === 'dashboard.run_worker_pass') {
                $tests .= ',tests/Unit/AssertionResourceCustodyTest.php';
            }
            if (in_array($symbol['id'], ['dashboard.run_checks', 'dashboard.run_worker_pass'], strict: true)) {
                $tests .= ',tests/Unit/ContainerHealthTest.php,tests/Network/ContainerHealthTest.php,tests/Http/ContainerHealthTest.php';
            }
            if ($symbol['id'] === 'dashboard.run_checks') {
                $tests .= ',tests/Network/ProcessDiagnosticTest.php,tests/Unit/HealthCheckDiagnosticsTest.php';
            }
            if ($symbol['id'] === 'dashboard.list_sites') {
                $tests .= ',tests/Unit/PresenterTest.php';
            }
            $testFiles = array_values(array_unique(explode(',', $tests)));
            $selectors = Tablo\Tools\NativeTestAttachments::selectors($testFiles);
            $nativeMap[$symbol['id']] = ['test_files' => $testFiles, 'native_selectors' => $selectors];
            $tests = implode(',', $selectors);
            run_gate([$lekalo, 'contract', 'attach', $symbol['id'], '--native-test', $tests, '--gate', 'native-php-tests']);

    }
    run_gate([$lekalo, 'contract', 'check', '--module', 'dashboard', '--no-cache']);
    run_gate([PHP_BINARY, 'vendor/bin/testo', 'run', '--path', 'tests/Unit', '--path', 'tests/Network', '--path', 'tests/Http']);
    if (!is_dir('artifacts')) { mkdir('artifacts'); }
    file_put_contents('artifacts/native-test-map.json', json_encode($nativeMap,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents('artifacts/verification.json', json_encode([
        'status' => 'passed', 'checked_at' => gmdate('c'), 'lekalo_lock' => hash_file('sha256', filename: 'lekalo.lock'),
        'composer_lock' => hash_file('sha256', filename: 'composer.lock'), 'bindings' => hash_file('sha256', filename: 'contracts/php-bindings.json'),
        'reviewed_dependencies' => hash_file('sha256', filename: 'contracts/reviewed-dependencies.json'),
        'gates' => ['mago-lint', 'mago-analyze', 'model-validation', 'lock-freshness', 'source-fingerprints', 'contract-conformance', 'native-logic-tests', 'real-http-client-tests', 'isolated-http-flow'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "All gates passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
