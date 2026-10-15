<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use RuntimeException;
use Tablo\Tests\Support\DashboardModesFixture;
use Tablo\Tests\Support\DashboardPortObserver;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\SessionEvidence;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Assert\State\Assertion\ComparisonFailure;
use Testo\Test;
use Throwable;
use WeakReference;

final class DashboardModesFixtureCleanupTest
{
    #[Test]
    public function closesTheRealPeerWhenWebConstructionThrows(): void
    { dashboard_failure_control('startup'); }

    #[Test]
    public function closesBothRealServersAndReleasesTheDatabaseWhenAuthenticationFails(): void
    { dashboard_failure_control('authentication'); }

    #[Test]
    public function successfulFixtureDisposalStillRemovesItsOwnedDirectories(): void
    { dashboard_success_control(); }

    #[Test]
    public function rejectsLiveResourcesAndAcceptsTheReleasedPort(): void
    { dashboard_oracle_control(); }

    #[Test]
    public function cleansDefaultSnapshotsAndRetainsLateFailures(): void
    { dashboard_default_snapshot_controls(); }

    #[Test]
    public function retainsTheDefaultSnapshotWhenFinalResourceObservationFails(): void
    {
        dashboard_final_cleanup_control();
        dashboard_final_cleanup_control(static function (TemporaryDirectory $owner): void {
            Assert::false(is_file($owner->path . '/web/test.sqlite'), 'Controlled primary before final resource observation');
        });
    }

    #[Test]
    public function enforcesTheObserverJournalAndNativeCompletionContract(): void
    { dashboard_observer_contract_controls(); }

    #[Test]
    public function exercisePropagatesFinalResourceErrorsAndPreservesTheOriginalPrimary(): void
    {
        dashboard_exercise_control();
        dashboard_exercise_control(static function (string $input): void {
            Assert::false(is_file($input . '/web/test.sqlite'), 'Controlled original primary inside exercise callback');
        });
    }
}

function dashboard_oracle_control(): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        $pid = getmypid();
        if ($pid === false) { throw new RuntimeException('Cannot observe the owned live PID'); }
        dashboard_expect_rejection(static fn () => dashboard_assert_pid_absent($pid), meaning: 'A live owned Unix PID cannot pass the absence oracle');
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    if (!is_resource($listener)) { throw new RuntimeException('Cannot create the owned live-port control'); }
    try {
        $address = stream_socket_get_name($listener, remote: false);
        if ($address === false) { throw new RuntimeException('Cannot observe the owned control port'); }
        $separator = strrpos($address, needle: ':');
        if ($separator === false) { throw new RuntimeException('Invalid owned control address'); }
        $port = (int) substr($address, $separator + 1);
        Assert::true($port > 0);
        dashboard_expect_rejection(static fn () => dashboard_released_port($port), meaning: 'A bound owned port cannot pass the release oracle');
    } finally { fclose($listener); }
    dashboard_released_port($port);
    SessionEvidence::record('dashboard-oracle-control', ['liveUnixPidApplicable' => PHP_OS_FAMILY !== 'Windows',
        'livePortRejected' => true, 'releasedPortRebound' => true, 'port' => $port]);
}

/** @param callable(): void $observe */
function dashboard_expect_rejection(callable $observe, string $meaning): void
{
    $failure = null;
    try { $observe(); }
    catch (ComparisonFailure $error) { $failure = $error; }
    Assert::true($failure instanceof ComparisonFailure, $meaning);
}

/** @param null|callable(?TemporaryDirectory): void $afterDisposal */
function dashboard_success_control(?callable $afterDisposal = null): void
{
    $fixture = new DashboardModesFixture();
    $paths = [$fixture->directory->path, $fixture->web->directory->path];
    $ports = [$fixture->peer->port, $fixture->web->server->port];
    $database = WeakReference::create($fixture->web->database());
    $snapshot = null;
    $primary = null;
    try {
        $snapshot = dashboard_success_snapshot($fixture);
        $fixture->close();
        Assert::same($database->get(), null, 'The actual parent PDO reference was released');
        foreach ($paths as $path) { Assert::false(is_dir($path), 'Successful cleanup removes owned fixture data'); }
        foreach ($ports as $port) { dashboard_released_port($port); }
        if ($afterDisposal !== null) { $afterDisposal($snapshot); }
    }
    catch (Throwable $error) {
        $primary = $error;
        $snapshot?->retainFailure($error);
        $fixture->directory->retainFailure($error); $fixture->web->directory->retainFailure($error); throw $error;
    }
    finally { dashboard_finish_success($fixture, $snapshot, $primary); }
}

function dashboard_finish_success(DashboardModesFixture $fixture, ?TemporaryDirectory $snapshot, ?Throwable $primary): void
{
    try { $fixture->close(); }
    catch (Throwable $cleanup) {
        if ($snapshot !== null) { \Tablo\Tests\Support\dashboard_cleanup_failure([$snapshot], $cleanup); }
        if ($primary === null) { throw $cleanup; }
    }
    finally { dashboard_release_default_snapshot($snapshot); }
}

function dashboard_release_default_snapshot(?TemporaryDirectory $snapshot): void
{
    if ($snapshot === null || $snapshot->hasFailure()) { return; }
    try {
        SessionEvidence::record('dashboard-default-snapshot-release', ['path' => $snapshot->path]);
        $snapshot->cleanup($snapshot->close(...));
        Assert::false(is_dir($snapshot->path), 'The successful default snapshot owner removes its copy');
    } catch (Throwable $error) { $snapshot->retainFailure($error); throw $error; }
}

function dashboard_default_snapshot_controls(): void
{
    $previous = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    putenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    try {
        dashboard_success_control(static function (?TemporaryDirectory $snapshot): void {
            $owner = dashboard_default_snapshot_owner($snapshot);
            Assert::true(is_file($owner->path . '/web/test.sqlite'), 'The default copy survives post-disposal assertions');
        });
        dashboard_late_snapshot_control();
    } finally {
        putenv($previous === false ? 'TABLO_HELPER_DIAGNOSTICS_DIRECTORY' : 'TABLO_HELPER_DIAGNOSTICS_DIRECTORY=' . $previous);
    }
}

function dashboard_default_snapshot_owner(?TemporaryDirectory $snapshot): TemporaryDirectory
{
    if ($snapshot === null) { throw new RuntimeException('The default snapshot must have a fresh directory owner'); }
    return $snapshot;
}

function dashboard_late_snapshot_control(): void
{
    /** @var array{owner: ?TemporaryDirectory, expected: ?ComparisonFailure} $control */
    $control = ['owner' => null, 'expected' => null];
    $actual = null;
    try {
        dashboard_success_control(static function (?TemporaryDirectory $snapshot) use (&$control): void {
            $owner = dashboard_default_snapshot_owner($snapshot);
            $control['owner'] = $owner;
            try { Assert::false(is_file($owner->path . '/web/test.sqlite'), 'Controlled late dashboard disposal assertion'); }
            catch (ComparisonFailure $primary) { $control['expected'] = $primary; throw $primary; }
        });
    } catch (ComparisonFailure $primary) { $actual = $primary; }
    Assert::true($actual instanceof ComparisonFailure, 'The actual late assertion failed');
    Assert::same($actual, $control['expected'], 'Cleanup preserves the exact late primary');
    $owner = dashboard_default_snapshot_owner($control['owner']);
    Assert::true($owner->hasFailure(), 'The owned default copy is retained for the late failure');
    Assert::true(is_file($owner->path . '/web/test.sqlite'), 'The actual late-failure database copy remains');
    Assert::true(is_file($owner->path . '/input-manifest.json'), 'The late-failure raw input manifest remains');
    $failures = dashboard_control_records($owner->path, kind: 'failure');
    Assert::true(count(array_filter($failures, static fn (array $record): bool => ($record['kind'] ?? null) === 'primary'
        && ($record['type'] ?? null) === ComparisonFailure::class)) > 0, 'The late primary has a sanitized retention record');
}

function dashboard_failure_control(string $stage): void
{
    $evidence = new TemporaryDirectory('tablo-dashboard-startup-evidence-');
    $previous = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $evidence->path);
    /** @var array{peer: ?TestServer, web: ?WebFixture, database: WeakReference<PDO>|null, nativePrimary: ?Throwable, fixture: ?DashboardModesFixture, phase: string} $resources */
    $resources = ['peer' => null, 'web' => null, 'database' => null, 'nativePrimary' => null, 'fixture' => null, 'phase' => 'constructing-peer'];
    $testError = null;
    try {
        $primary = dashboard_capture_failure($stage, $resources);
        dashboard_primary_diagnostic($stage, $resources['phase'], $primary);
        $evidence->retainFailure($primary); // Deliberate diagnostic retention, after resources close.
        dashboard_assert_primary($stage, $primary, $resources['nativePrimary'], $resources['database']);
        $starts = dashboard_control_records($evidence->path, kind: 'server-start');
        $closes = dashboard_control_records($evidence->path, kind: 'server-close');
        Assert::same(count($starts), $stage === 'startup' ? 1 : 2, 'Count actually started processes');
        Assert::same(count($closes), count($starts), 'Every acquired real server closed before the error returned');
        foreach ($starts as $start) { dashboard_assert_server($start, $closes, $primary); }
        dashboard_released_port(FixtureValue::object($resources['peer'])->port);
        if ($resources['web'] !== null) { dashboard_assert_database($resources['web']); }
        SessionEvidence::record('dashboard-startup-control', ['stage' => $stage, 'primaryType' => $primary::class,
            'primaryUnmasked' => true, 'ownedServers' => count($starts), 'portsRebound' => true,
            'parentPdoCollected' => $resources['database'] === null || $resources['database']->get() === null]);
    } catch (Throwable $error) {
        $testError = $error; $evidence->retainFailure($error); throw $error;
    } finally { dashboard_finish_control($evidence, $resources, $previous, $testError); }
}

/** @param array{peer: ?TestServer, web: ?WebFixture, database: WeakReference<PDO>|null, nativePrimary: ?Throwable, fixture: ?DashboardModesFixture, phase: string} $resources */
function dashboard_capture_failure(string $stage, array &$resources): Throwable
{
    try {
        $resources['fixture'] = new DashboardModesFixture(static function (array $environment, TestServer $startedPeer) use ($stage, &$resources): WebFixture {
            $resources['peer'] = $startedPeer;
            $resources['phase'] = 'constructing-web';
            if ($stage === 'startup') {
                // Native socket parsing fails before a second child can start; the peer is real.
                try { return new WebFixture(true, host: "127.0.0.1\0", environment: $environment); }
                catch (Throwable $error) { $resources['nativePrimary'] = $error; throw $error; }
            }
            $web = new WebFixture(true, router: dirname(__DIR__) . '/fixtures/dashboard-modes-startup-failure.php', environment: $environment);
            $resources['web'] = $web;
            $resources['phase'] = 'writing-clock';
            \Tablo\Tests\Support\write_dashboard_clock($web, DashboardModesFixture::EPOCH);
            $resources['phase'] = 'initial-setup-get';
            $response = $web->request('/setup');
            SessionEvidence::record('dashboard-auth-response', ['path' => '/setup', 'method' => 'GET', 'status' => $response['status']]);
            Assert::same($response['status'], 200);
            $resources['phase'] = 'acquiring-parent-pdo';
            $resources['database'] = WeakReference::create($web->database());
            $resources['phase'] = 'authenticating';
            return $web;
        });
    } catch (Throwable $error) { return $error; }
    throw new RuntimeException('Controlled startup or authentication refusal did not occur');
}

function dashboard_primary_diagnostic(string $stage, string $phase, Throwable $primary): void
{
    $message = $primary->getMessage();
    $classification = match (true) {
        str_starts_with($message, 'HTTP fixture request failed:') => str_contains(strtolower($message), 'timed out') ? 'http-transport-timeout' : 'http-transport-other',
        str_contains($message, 'Fixture administrator setup') => 'administrator-setup-assertion',
        $primary instanceof ComparisonFailure => 'other-comparison-assertion',
        default => 'other',
    };
    $root = str_replace(search: '\\', replace: '/', subject: dirname(__DIR__, levels: 2)) . '/';
    $file = str_replace(search: '\\', replace: '/', subject: $primary->getFile());
    SessionEvidence::record('dashboard-primary-diagnostic', ['stage' => $stage, 'phase' => $phase,
        'type' => $primary::class, 'classification' => $classification, 'line' => $primary->getLine(),
        'file' => str_starts_with($file, $root) ? substr($file, strlen($root)) : 'outside-owned-source',
        'qualification' => 'Fixed classifications only; original exception message and credentials are not exported']);
}

/** @param WeakReference<PDO>|null $database */
function dashboard_assert_primary(string $stage, Throwable $primary, ?Throwable $nativePrimary, ?WeakReference $database): void
{
    if ($stage === 'startup') {
        Assert::same($primary, $nativePrimary, 'Cleanup preserves the exact native startup exception');
        return;
    }
    Assert::true(str_contains($primary->getMessage(), 'Fixture administrator setup'), 'Authentication remains the primary failure');
    Assert::same($primary->getPrevious(), null, 'The primary is rethrown without a cleanup wrapper');
    Assert::same(FixtureValue::object($database)->get(), null, 'The actual parent PDO was collected before retention');
}

/** @param array<array-key, mixed> $start
 * @param list<array<array-key, mixed>> $closes */
function dashboard_assert_server(array $start, array $closes, Throwable $primary): void
{
    $pid = \Tablo\SqlColumn::integer($start, 'pid');
    $matches = array_values(array_filter($closes, static fn (array $record): bool => \Tablo\SqlColumn::integer($record, 'pid') === $pid));
    Assert::same(count($matches), 1, 'Closure matches the actual direct process identity');
    $receipt = FixtureValue::row($matches[0] ?? null);
    Assert::same($receipt['closed'] ?? null, true);
    dashboard_assert_termination($pid, $receipt);
    $path = \Tablo\SqlColumn::string($start, 'fixture');
    Assert::true(is_dir($path), 'Failed input directories remain after process closure');
    Assert::true(is_file($path . '/server.stderr'), 'Original server diagnostics are retained');
    $failures = dashboard_control_records($path, kind: 'failure');
    Assert::true(count(array_filter($failures, static fn (array $record): bool => ($record['kind'] ?? null) === 'primary' && ($record['type'] ?? null) === $primary::class)) > 0, 'The common sanitized primary-failure record is retained');
}

/** @param array<array-key, mixed> $receipt */
function dashboard_assert_termination(int $pid, array $receipt): void
{
    if (PHP_OS_FAMILY !== 'Windows') { dashboard_assert_pid_absent($pid); return; }
    Assert::true(is_int($receipt['observedExit'] ?? null) || (is_int($receipt['procClose'] ?? null) && $receipt['procClose'] !== -1), 'A native direct-child exit was observed');
}

function dashboard_assert_pid_absent(int $pid): void
{
    if (!function_exists('posix_kill') || !function_exists('posix_get_last_error')) {
        throw new RuntimeException('The Unix process-absence observer is required');
    }
    $exists = posix_kill($pid, signal: 0);
    $error = posix_get_last_error();
    SessionEvidence::record('dashboard-process-observation', ['pid' => $pid, 'signal' => 0, 'exists' => $exists, 'errno' => $error]);
    Assert::false($exists, 'The recorded owned Unix process is absent');
    Assert::same($error, 3, 'ESRCH positively identifies an absent process');
}

function dashboard_assert_database(WebFixture $web): void
{
    dashboard_released_port($web->server->port);
    Assert::true(is_file($web->directory->path . '/test.sqlite'), 'Real initialized database bytes are retained');
    $reopened = new PDO('sqlite:' . $web->directory->path . '/test.sqlite');
    $statement = \Tablo\SqlStatement::checked($reopened->query('SELECT COUNT(*) FROM users'));
    try { Assert::same((int) $statement->fetchColumn(), 0, 'The refusal did not create an administrator'); }
    finally { $statement->closeCursor(); unset($statement, $reopened); }
    Assert::true(str_contains($web->server->diagnostics(), 'Controlled dashboard authentication refusal'));
}

/** @param array{peer: ?TestServer, web: ?WebFixture, database: WeakReference<PDO>|null, nativePrimary: ?Throwable, fixture: ?DashboardModesFixture, phase: string} $resources */
function dashboard_finish_control(TemporaryDirectory $evidence, array $resources,
    string|false $previous, ?Throwable $primary): void
{
    $cleanup = null;
    // Independent handles prevent a failed regression assertion from stranding real children.
    foreach ([
        static function () use ($resources): void { $resources['fixture']?->close(); },
        static function () use ($resources): void { $resources['web']?->reopenDatabase(); $resources['web']?->server->close(); },
        static function () use ($resources): void { $resources['peer']?->close(); },
        static function () use ($evidence): void { dashboard_export_control($evidence); },
        $evidence->close(...),
    ] as $release) {
        try { $release(); }
        catch (Throwable $error) { $cleanup ??= $error; }
    }
    putenv($previous === false ? 'TABLO_SESSION_EVIDENCE_DIRECTORY' : 'TABLO_SESSION_EVIDENCE_DIRECTORY=' . $previous);
    if ($cleanup === null) { return; }
    $evidence->recordCleanupFailure($cleanup);
    if ($primary === null) { throw $cleanup; }
}

function dashboard_released_port(int $port): void
{
    if (PHP_OS_FAMILY === 'Windows') { dashboard_assert_windows_port($port); }
    $errno = 0; $error = '';
    $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
    Assert::true(is_resource($socket), 'The owned port can be rebound after the real child closes');
    if (is_resource($socket)) { fclose($socket); }
}

function dashboard_assert_windows_port(int $port): void
{
    $directory = new TemporaryDirectory('tablo-dashboard-port-observer-');
    try {
        $system = getenv('SystemRoot');
        if ($system === false || $system === '') { throw new RuntimeException('Windows port observer is unavailable'); }
        $script = dirname(__DIR__) . '/fixtures/dashboard-port-observation.ps1';
        $scriptHash = hash_file('sha256', $script);
        if ($scriptHash === false) { throw new RuntimeException('Windows port observer input is unavailable'); }
        $journal = $directory->path . '/stages.jsonl';
        $command = [$system . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-File', $script, '-Port', (string) $port, '-Journal', $journal];
        $result = DashboardPortObserver::run($command, $directory);
        $observation = dashboard_accept_observer($result, $directory, $port);
        $available = dashboard_windows_observation($observation, $port);
        SessionEvidence::rawFile($journal, 'dashboard-port-stages');
        foreach (['launch-requested', 'native-completion'] as $stage) {
            SessionEvidence::rawFile($directory->path . '/budget-' . $stage . '.json', 'dashboard-port-budget-' . $stage);
        }
        dashboard_write_manifest($directory->path . '/observation.json', ['port' => $port, 'observation' => $observation,
            'script_sha256' => $scriptHash, 'journal_sha256' => \Tablo\RequiredFile::digest($journal)]);
        SessionEvidence::record('dashboard-port-observation', ['port' => $port, 'observation' => $observation,
            'script_sha256' => $scriptHash, 'journal_sha256' => \Tablo\RequiredFile::digest($journal)]);
        Assert::true($available, 'The Windows kernel grants exclusive use of the owned port');
    } catch (Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->cleanup(static fn () => dashboard_finish_observer($directory)); }
}

/** @param array{exit_code: ?int, stdout: string, stderr: string, files: array{stdout: string, stderr: string, receipt: string}} $result
 * @return array<array-key, mixed> */
function dashboard_accept_observer(array $result, TemporaryDirectory $directory, int $port): array
{
    if ($result['exit_code'] !== 0 || $result['stderr'] !== '') { throw new RuntimeException('Windows port observer did not complete cleanly'); }
    $observation = \Tablo\JsonRecord::decode($result['stdout'], 32);
    dashboard_windows_observation($observation, $port);
    $receipt = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($result['files']['receipt'])), 32);
    dashboard_observer_closed($receipt, $observation);
    dashboard_observer_journal($directory->path . '/stages.jsonl', $port, $observation);
    return $observation;
}

/** @param array<array-key, mixed> $observation */
function dashboard_windows_observation(array $observation, int $port): bool
{
    dashboard_observer_header($observation, $port);
    $state = dashboard_observer_scalar($observation['state'] ?? null);
    $socketError = dashboard_observer_scalar($observation['socket_error'] ?? null);
    $nativeError = dashboard_observer_scalar($observation['native_error'] ?? null);
    if ($state === 'available' && $socketError === null && $nativeError === null) { return true; }
    if ($state === 'occupied' && (($socketError === 'AddressAlreadyInUse' && $nativeError === 10_048)
        || ($socketError === 'AccessDenied' && $nativeError === 10_013))) { return false; }
    throw new RuntimeException('Windows port observer outcome is unknown');
}

/** @param array<array-key, mixed> $observation */
function dashboard_observer_header(array $observation, int $port): void
{
    if (($observation['port'] ?? null) !== $port || ($observation['exclusive'] ?? null) !== true) {
        throw new RuntimeException('Windows port observer did not observe the owned port exclusively');
    }
    $pid = dashboard_observer_scalar($observation['pid'] ?? null);
    $elapsed = dashboard_observer_scalar($observation['elapsed_ms'] ?? null);
    if (!is_int($pid) || $pid < 1 || (!is_float($elapsed) && !is_int($elapsed)) || $elapsed < 0) {
        throw new RuntimeException('Windows port observer identity or timing is unknown');
    }
    foreach (['socket_error', 'native_error'] as $field) {
        if (!array_key_exists($field, $observation)) { throw new RuntimeException('Windows port observer omitted its native outcome'); }
    }
}

/** @param array<array-key, mixed> $receipt
 * @param array<array-key, mixed> $observation */
function dashboard_observer_closed(array $receipt, array $observation): void
{
    if (($receipt['pid'] ?? null) !== ($observation['pid'] ?? null) || ($receipt['closed'] ?? null) !== true
        || ($receipt['root_exit_observed'] ?? null) !== true || ($receipt['exit_code'] ?? null) !== 0) {
        throw new RuntimeException('Windows observer direct-child closure is unknown');
    }
}

/** @param array<array-key, mixed> $observation */
function dashboard_observer_journal(string $path, int $port, array $observation): void
{
    if (!is_file($path)) { throw new RuntimeException('Windows observer script entry was not observed'); }
    $lines = \Tablo\Tests\Support\FixtureFiles::lines($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $stages = ['entry', 'binding', 'released', 'complete'];
    if (count($lines) !== count($stages)) { throw new RuntimeException('Windows observer stage journal is incomplete'); }
    $previous = 0;
    foreach ($lines as $index => $line) {
        $stage = \Tablo\JsonRecord::decode($line, 16);
        $elapsed = dashboard_observer_scalar($stage['elapsed_ms'] ?? null);
        if (($stage['pid'] ?? null) !== ($observation['pid'] ?? null) || ($stage['port'] ?? null) !== $port
            || ($stage['stage'] ?? null) !== FixtureValue::at($stages, $index) || (!is_int($elapsed) && !is_float($elapsed))
            || !is_finite($elapsed) || $elapsed < $previous) { throw new RuntimeException('Windows observer stage identity, order or timing is unknown'); }
        $previous = $elapsed;
    }
}

function dashboard_observer_scalar(mixed $value): int|float|string|null
{
    if (is_int($value) || is_float($value) || is_string($value) || $value === null) { return $value; }
    throw new RuntimeException('Windows port observer returned a non-scalar field');
}

function dashboard_finish_observer(TemporaryDirectory $directory): void
{
    $sink = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    if ($sink && is_dir($sink)) { dashboard_copy_control($directory->path, $sink . '/dashboard-port-' . bin2hex(random_bytes(8))); }
    $directory->close();
}

function dashboard_success_snapshot(DashboardModesFixture $fixture): ?TemporaryDirectory
{
    $sink = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    if ($sink && is_dir($sink)) {
        dashboard_capture_success_input($fixture, $sink . '/dashboard-success-input-' . bin2hex(random_bytes(8)), retention: 'configured-sink');
        return null;
    }
    $owner = new TemporaryDirectory('tablo-dashboard-success-input-');
    try { dashboard_capture_success_input($fixture, $owner->path, retention: 'managed-default'); }
    catch (Throwable $primary) { $owner->retainFailure($primary); throw $primary; }
    return $owner;
}

function dashboard_capture_success_input(DashboardModesFixture $fixture, string $target, string $retention): void
{
    SessionEvidence::record('dashboard-success-snapshot-started', ['path' => $target, 'retention' => $retention]);
    $files = [];
    dashboard_copy_control($fixture->directory->path, $target . '/peer', $files);
    dashboard_copy_control($fixture->web->directory->path, $target . '/web', $files);
    dashboard_write_manifest($target . '/input-manifest.json', ['phase' => 'before-fixture-close', 'files' => $files,
        'captured_at_utc' => gmdate('c'),
        'qualification' => 'Raw per-file input bytes before disposal; WAL/SHM are copied when present, without claiming a coherent logical DB backup or reconstruction of any earlier deleted DB']);
    SessionEvidence::record('dashboard-success-input', ['path' => $target, 'retention' => $retention, 'files' => $files]);
}

/** @param array<array-key, mixed> $data */
function dashboard_write_manifest(string $path, array $data): void
{
    $bytes = \Tablo\RequiredFile::bytes(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    if (file_put_contents($path, $bytes) !== strlen($bytes)) { throw new RuntimeException('Cannot preserve dashboard observer input evidence'); }
}

/** @return list<array<array-key, mixed>> */
function dashboard_control_records(string $path, string $kind): array
{
    $files = glob($path . '/' . $kind . '-*.json');
    if ($files === false) { throw new RuntimeException('Cannot enumerate owned startup evidence'); }
    return array_map(static fn (string $file): array => \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($file)), 32), $files);
}

function dashboard_export_control(TemporaryDirectory $evidence): void
{
    $sink = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    if (!$sink || !is_dir($sink)) { return; }
    $target = $sink . '/dashboard-startup-' . bin2hex(random_bytes(8));
    dashboard_copy_control($evidence->path, $target . '/evidence');
    foreach (dashboard_control_records($evidence->path, kind: 'server-start') as $index => $record) {
        dashboard_copy_control(\Tablo\SqlColumn::string($record, 'fixture'), $target . '/fixture-' . $index);
    }
}

/** @param list<array{source: string, copy: string, bytes: int, sha256: string, source_before_sha256: string|false, source_after_sha256: string|false, captured_at_utc: string, source_mtime_utc: ?string}> $files */
function dashboard_copy_control(string $source, string $target, array &$files = []): void
{
    if (!mkdir($target, permissions: 0o700, recursive: true) && !is_dir($target)) { throw new RuntimeException('Cannot retain owned startup evidence'); }
    foreach (new \DirectoryIterator($source) as $file) {
        if ($file->isDot()) { continue; }
        if ($file->isLink()) { throw new RuntimeException('Linked startup evidence is unsupported'); }
        $destination = $target . '/' . $file->getFilename();
        if ($file->isDir()) { dashboard_copy_control($file->getPathname(), $destination, $files); continue; }
        $before = hash_file('sha256', $file->getPathname());
        $mtime = filemtime($file->getPathname());
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($file->getPathname()));
        $hash = hash('sha256', $bytes);
        if (file_put_contents($destination, $bytes) !== strlen($bytes) || hash_file('sha256', $destination) !== $hash) {
            throw new RuntimeException('Cannot preserve exact owned dashboard input bytes');
        }
        $files[] = ['source' => $file->getPathname(), 'copy' => $destination, 'bytes' => strlen($bytes), 'sha256' => $hash,
            'source_before_sha256' => $before, 'source_after_sha256' => hash_file('sha256', $file->getPathname()),
            'captured_at_utc' => gmdate('c'), 'source_mtime_utc' => $mtime === false ? null : gmdate('c', $mtime)];
    }
}

/** @param null|callable(TemporaryDirectory): void $priorAssertion */
function dashboard_final_cleanup_control(?callable $priorAssertion = null): void
{
    $evidence = new TemporaryDirectory('tablo-dashboard-final-evidence-');
    $previous = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    $diagnostics = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $evidence->path);
    putenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    $evidence->retainFailure(new RuntimeException('Controlled final cleanup input retention'));
    try { dashboard_final_cleanup_branch($evidence, $priorAssertion); }
    catch (Throwable $error) { $evidence->retainFailure($error); throw $error; }
    finally {
        putenv($previous === false ? 'TABLO_SESSION_EVIDENCE_DIRECTORY' : 'TABLO_SESSION_EVIDENCE_DIRECTORY=' . $previous);
        putenv($diagnostics === false ? 'TABLO_HELPER_DIAGNOSTICS_DIRECTORY' : 'TABLO_HELPER_DIAGNOSTICS_DIRECTORY=' . $diagnostics);
        SessionEvidence::record('dashboard-final-evidence-location', ['path' => $evidence->path, 'priorPrimary' => $priorAssertion !== null]);
    }
}

/** @param null|callable(TemporaryDirectory): void $priorAssertion */
function dashboard_final_cleanup_branch(TemporaryDirectory $evidence, ?callable $priorAssertion): void
{
    /** @var array{owner: ?TemporaryDirectory, listener: resource|null, port: ?int, expected: ?ComparisonFailure} $control */
    $control = ['owner' => null, 'listener' => null, 'port' => null, 'expected' => null];
    $actual = null;
    try {
        dashboard_success_control(static function (?TemporaryDirectory $snapshot) use ($evidence, $priorAssertion, &$control): void {
            dashboard_final_listener($snapshot, $evidence, $priorAssertion, $control);
        });
    } catch (Throwable $error) { $actual = $error; }
    finally {
        if (is_resource($control['listener'])) {
            $closed = fclose($control['listener']);
            SessionEvidence::record('dashboard-final-listener-closed', ['port' => $control['port'], 'closed' => $closed]);
            Assert::true($closed, 'The directly owned final-control listener closes');
            dashboard_released_port(FixtureValue::integer($control['port']));
        }
    }
    $owner = dashboard_default_snapshot_owner($control['owner']);
    SessionEvidence::record('dashboard-final-result', ['priorPrimary' => $priorAssertion !== null, 'type' => $actual === null ? null : $actual::class,
        'defaultCopyPresent' => is_dir($owner->path), 'defaultCopyRetained' => $owner->hasFailure(),
        'preFinalCopyPresent' => is_file($evidence->path . '/input-before-final/web/test.sqlite')]);
    dashboard_assert_final_failure($actual, $control['expected']);
    dashboard_final_snapshot_retained($owner, $evidence, $control['expected']);
}

function dashboard_assert_final_failure(?Throwable $actual, ?ComparisonFailure $expected): void
{
    if ($expected !== null) { Assert::same($actual, $expected, 'Final cleanup preserves the exact original primary'); return; }
    Assert::true($actual instanceof RuntimeException, 'Final resource observation must propagate its new cleanup failure');
    Assert::same(FixtureValue::object($actual)->getMessage(), 'Owned listener remains after closure', 'The actual final resource observation is the failure');
}

/** @param array{owner: ?TemporaryDirectory, listener: resource|null, port: ?int, expected: ?ComparisonFailure} $control
 * @param null|callable(TemporaryDirectory): void $priorAssertion */
function dashboard_final_listener(?TemporaryDirectory $snapshot, TemporaryDirectory $evidence, ?callable $priorAssertion, array &$control): void
{
    $owner = dashboard_default_snapshot_owner($snapshot);
    $control['owner'] = $owner;
    dashboard_copy_control($owner->path, $evidence->path . '/input-before-final');
    $port = dashboard_final_peer_port($evidence);
    $control['port'] = $port;
    $listener = stream_socket_server('tcp://127.0.0.1:' . $port);
    if (!is_resource($listener)) { throw new RuntimeException('Cannot acquire the owned final-cleanup listener'); }
    $control['listener'] = $listener;
    $address = stream_socket_get_name($listener, remote: false);
    Assert::same($address, '127.0.0.1:' . $port, 'The final control owns the actual released peer port');
    SessionEvidence::record('dashboard-final-listener', ['port' => $port, 'address' => $address,
        'snapshot' => $owner->path, 'inputBeforeFinal' => $evidence->path . '/input-before-final', 'priorPrimary' => $priorAssertion !== null]);
    dashboard_expect_rejection(static fn () => dashboard_released_port($port), meaning: 'The final-control exact peer port is actually occupied');
    SessionEvidence::record('dashboard-final-occupied-confirmed', ['port' => $port, 'priorPrimary' => $priorAssertion !== null]);
    if ($priorAssertion === null) { return; }
    try { $priorAssertion($owner); }
    catch (ComparisonFailure $primary) { $control['expected'] = $primary; throw $primary; }
}

function dashboard_final_peer_port(TemporaryDirectory $evidence): int
{
    $closures = dashboard_control_records($evidence->path, kind: 'dashboard-closure');
    Assert::same(count($closures), 1, 'The first actual fixture close completed before the final control');
    $closure = FixtureValue::row($closures[0] ?? null);
    $ports = \Tablo\JsonRecord::checked($closure['ports'] ?? null);
    if (count($ports) !== 2 || !array_is_list($ports)) { throw new RuntimeException('Actual disposed fixture ports are unavailable'); }
    $starts = dashboard_control_records($evidence->path, kind: 'server-start');
    $closes = dashboard_control_records($evidence->path, kind: 'server-close');
    Assert::same(count($starts), 2, 'The control acquired two real direct servers');
    Assert::same(count($closes), 2, 'Both real direct servers closed before the occupied-port control');
    foreach ($starts as $start) {
        $pid = \Tablo\SqlColumn::integer($start, 'pid');
        $matching = array_values(array_filter($closes, static fn (array $record): bool => ($record['pid'] ?? null) === $pid));
        Assert::same(count($matching), 1, 'The actual closure receipt matches the direct server PID');
        $receipt = FixtureValue::row($matching[0] ?? null);
        Assert::same($receipt['closed'] ?? null, true);
        dashboard_assert_termination($pid, $receipt);
    }
    return FixtureValue::integer(FixtureValue::at($ports, 1));
}

function dashboard_final_snapshot_retained(TemporaryDirectory $owner, TemporaryDirectory $evidence, ?ComparisonFailure $primary): void
{
    Assert::true($owner->hasFailure(), 'The final failure retains the managed default snapshot');
    Assert::true(is_file($owner->path . '/web/test.sqlite'), 'The actual final-failure DB copy remains');
    Assert::true(is_file($owner->path . '/input-manifest.json'), 'The actual final-failure manifest remains');
    Assert::same(\Tablo\RequiredFile::digest($owner->path . '/web/test.sqlite'),
        \Tablo\RequiredFile::digest($evidence->path . '/input-before-final/web/test.sqlite'), 'The retained DB bytes equal the pre-final input');
    $records = dashboard_control_records($owner->path, kind: 'failure');
    Assert::true(count(array_filter($records, static fn (array $record): bool => ($record['kind'] ?? null) === 'cleanup'
        && ($record['type'] ?? null) === RuntimeException::class)) > 0, 'The new final-cleanup error has a sanitized retention record');
    if ($primary !== null) {
        Assert::true(count(array_filter($records, static fn (array $record): bool => ($record['kind'] ?? null) === 'primary'
            && ($record['type'] ?? null) === ComparisonFailure::class)) > 0, 'The exact original primary also has a sanitized retention record');
    }
}

function dashboard_observer_contract_controls(): void
{
    $directory = new TemporaryDirectory('tablo-dashboard-observer-journal-');
    $primary = null;
    try {
        dashboard_journal_controls($directory);
        if (PHP_OS_FAMILY !== 'Windows') { return; }
        foreach (['late-entry', 'incomplete', 'lingering'] as $mode) { dashboard_observer_process_control($mode); }
    } catch (Throwable $error) { $primary = $error; $directory->retainFailure($error); throw $error; }
    finally { dashboard_finish_observer_control($directory, $primary); }
}

function dashboard_journal_controls(TemporaryDirectory $directory): void
{
    $stages = [];
    foreach (['entry', 'binding', 'released', 'complete'] as $index => $stage) {
        $stages[] = ['pid' => 1, 'port' => 1, 'stage' => $stage, 'elapsed_ms' => $index];
    }
    $path = $directory->path . '/valid.jsonl';
    dashboard_write_stages($path, $stages);
    dashboard_observer_journal($path, port: 1, observation: ['pid' => 1]);
    $cases = ['missing-entry' => array_slice($stages, offset: 1), 'out-of-order' => [FixtureValue::at($stages, 1), FixtureValue::at($stages, 0), FixtureValue::at($stages, 2), FixtureValue::at($stages, 3)]];
    foreach (['pid' => 2, 'port' => 2, 'elapsed_ms' => -1, 'stage' => 'unknown'] as $field => $value) {
        $changed = $stages;
        $changed[2] = array_replace(FixtureValue::at($stages, 2), [$field => $value]);
        $cases['wrong-' . $field] = $changed;
    }
    foreach ($cases as $name => $rows) {
        $path = $directory->path . '/' . $name . '.jsonl';
        dashboard_write_stages($path, $rows);
        $failure = null;
        try { dashboard_observer_journal($path, port: 1, observation: ['pid' => 1]); }
        catch (RuntimeException $error) { $failure = $error; $directory->retainFailure($error); }
        Assert::true($failure instanceof RuntimeException, 'Incomplete or wrong-identity stage data cannot pass');
    }
    SessionEvidence::record('dashboard-observer-journal-controls', ['path' => $directory->path, 'cases' => count($cases),
        'qualification' => 'Data-level protocol controls; no native port availability is inferred from these synthetic records']);
}

/** @param list<array<string, mixed>> $stages */
function dashboard_write_stages(string $path, array $stages): void
{
    $bytes = '';
    foreach ($stages as $stage) { $bytes .= \Tablo\RequiredFile::bytes(json_encode($stage, JSON_THROW_ON_ERROR)) . "\n"; }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) { throw new RuntimeException('Cannot write owned observer journal control'); }
}

function dashboard_observer_process_control(string $mode): void
{
    $directory = new TemporaryDirectory('tablo-dashboard-observer-contract-');
    $primary = null;
    try {
        $system = getenv('SystemRoot');
        if ($system === false || $system === '') { throw new RuntimeException('Windows control observer is unavailable'); }
        $port = dashboard_observer_control_port();
        dashboard_write_observer_control($directory);
        $command = [$system . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive',
            '-File', $directory->path . '/control.ps1', '-Observer', dirname(__DIR__) . '/fixtures/dashboard-port-observation.ps1',
            '-Port', (string) $port, '-Journal', $directory->path . '/stages.jsonl', '-Mode', $mode];
        if ($mode === 'lingering') { dashboard_lingering_observer($command, $directory, $port); return; }
        $result = DashboardPortObserver::run($command, $directory);
        dashboard_observer_result_control($mode, $result, $directory, $port);
        SessionEvidence::record('dashboard-observer-contract-control', ['mode' => $mode, 'port' => $port, 'path' => $directory->path]);
    } catch (Throwable $error) { $primary = $error; $directory->retainFailure($error); throw $error; }
    finally { dashboard_finish_observer_control($directory, $primary); }
}

/** @param array{exit_code: ?int, stdout: string, stderr: string, files: array{stdout: string, stderr: string, receipt: string}} $result */
function dashboard_observer_result_control(string $mode, array $result, TemporaryDirectory $directory, int $port): void
{
    if ($mode === 'incomplete') {
        $failure = null;
        try { dashboard_accept_observer($result, $directory, $port); }
        catch (RuntimeException $error) { $failure = $error; $directory->retainFailure($error); }
        Assert::same($failure?->getMessage(), 'Windows observer stage journal is incomplete', 'Native exit alone cannot replace the completion journal');
        return;
    }
    $observation = dashboard_accept_observer($result, $directory, $port);
    Assert::true(dashboard_windows_observation($observation, $port), 'Delayed entry still performs a real exclusive free-port probe');
    $timing = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($directory->path . '/budget-native-completion.json')), 16);
    $elapsed = dashboard_observer_scalar($timing['elapsed_ms'] ?? null);
    Assert::true((is_int($elapsed) || is_float($elapsed)) && $elapsed >= 200, 'The entry delay consumes the original parent budget');
}

function dashboard_finish_observer_control(TemporaryDirectory $directory, ?Throwable $primary): void
{
    try { dashboard_finish_observer($directory); }
    catch (Throwable $cleanup) {
        $directory->recordCleanupFailure($cleanup);
        if ($primary === null) { throw $cleanup; }
    }
}

function dashboard_observer_control_port(): int
{
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    if (!is_resource($listener)) { throw new RuntimeException('Cannot acquire an owned observer control port'); }
    try {
        $address = stream_socket_get_name($listener, remote: false);
        if ($address === false) { throw new RuntimeException('Cannot observe the owned observer control port'); }
        $separator = strrpos($address, needle: ':');
        if ($separator === false) { throw new RuntimeException('Invalid owned observer control address'); }
        return (int) substr($address, $separator + 1);
    } finally { fclose($listener); }
}

function dashboard_write_observer_control(TemporaryDirectory $directory): void
{
    $bytes = <<<'POWERSHELL'
param([string]$Observer,[int]$Port,[string]$Journal,[string]$Mode)
$ErrorActionPreference = 'Stop'
if ($Mode -eq 'late-entry') { Start-Sleep -Milliseconds 200 }
& $Observer -Port $Port -Journal $Journal
if ($Mode -eq 'incomplete') {
    [System.IO.File]::Copy($Journal, $Journal + '.complete')
    $first = ([System.IO.File]::ReadAllLines($Journal))[0]
    [System.IO.File]::WriteAllText($Journal, $first + [Environment]::NewLine, [System.Text.UTF8Encoding]::new($false))
}
if ($Mode -eq 'lingering') { Start-Sleep -Seconds 30 }
POWERSHELL;
    if (file_put_contents($directory->path . '/control.ps1', $bytes) !== strlen($bytes)) { throw new RuntimeException('Cannot write the owned observer process control'); }
}

/** @param list<string> $command */
function dashboard_lingering_observer(array $command, TemporaryDirectory $directory, int $port): void
{
    $failure = null;
    try { DashboardPortObserver::run($command, $directory); }
    catch (RuntimeException $error) { $failure = $error; }
    Assert::true($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'stage=timeout'), 'Complete output from a still-live observer cannot pass');
    $stdout = FixtureValue::string(\Tablo\RequiredFile::bytes(file_get_contents(\Tablo\Tests\Support\FixtureFiles::first($directory->path . '/command-*.out'))));
    $observation = \Tablo\JsonRecord::decode($stdout, 32);
    Assert::true(dashboard_windows_observation($observation, $port), 'The lingering child really performed the exclusive probe');
    dashboard_observer_journal($directory->path . '/stages.jsonl', $port, $observation);
    $receipt = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents(\Tablo\Tests\Support\FixtureFiles::first($directory->path . '/command-*.json'))), 32);
    Assert::same($receipt['pid'] ?? null, $observation['pid'] ?? null, 'Closure belongs to the actual lingering observer');
    Assert::same($receipt['closed'] ?? null, true);
    Assert::same($receipt['root_exit_observed'] ?? null, true);
    $exit = dashboard_observer_scalar($receipt['exit_code'] ?? null);
    Assert::true(is_int($exit) && $exit !== -1, 'The timed-out observer has an actual native exit');
    Assert::true($directory->hasFailure(), 'All failed observer inputs remain retained');
    SessionEvidence::record('dashboard-observer-contract-control', ['mode' => 'lingering', 'port' => $port, 'path' => $directory->path,
        'pid' => $observation['pid'] ?? null, 'exit' => $exit, 'closed' => true, 'timeoutRejected' => true]);
}

/** @param null|callable(string): void $priorAssertion */
function dashboard_exercise_control(?callable $priorAssertion = null): void
{
    $evidence = new TemporaryDirectory('tablo-dashboard-exercise-evidence-');
    $previous = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    $diagnostics = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $evidence->path);
    putenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
    $evidence->retainFailure(new RuntimeException('Controlled exercise input retention'));
    try { dashboard_exercise_branch($evidence, $priorAssertion); }
    catch (Throwable $error) { $evidence->retainFailure($error); throw $error; }
    finally {
        putenv($previous === false ? 'TABLO_SESSION_EVIDENCE_DIRECTORY' : 'TABLO_SESSION_EVIDENCE_DIRECTORY=' . $previous);
        putenv($diagnostics === false ? 'TABLO_HELPER_DIAGNOSTICS_DIRECTORY' : 'TABLO_HELPER_DIAGNOSTICS_DIRECTORY=' . $diagnostics);
        SessionEvidence::record('dashboard-exercise-evidence-location', ['path' => $evidence->path, 'priorPrimary' => $priorAssertion !== null]);
    }
}

/** @param null|callable(string): void $priorAssertion */
function dashboard_exercise_branch(TemporaryDirectory $evidence, ?callable $priorAssertion): void
{
    /** @var array{listener: resource|null, port: ?int, expected: ?ComparisonFailure} $control */
    $control = ['listener' => null, 'port' => null, 'expected' => null];
    $actual = null;
    try {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture) use ($evidence, $priorAssertion, &$control): void {
            dashboard_exercise_listener($fixture, $evidence, $priorAssertion, $control);
        });
    } catch (Throwable $error) { $actual = $error; }
    finally {
        if (is_resource($control['listener'])) {
            $closed = fclose($control['listener']);
            SessionEvidence::record('dashboard-exercise-listener-closed', ['port' => $control['port'], 'closed' => $closed]);
            Assert::true($closed, 'The directly owned exercise listener closes');
            dashboard_released_port(FixtureValue::integer($control['port']));
        }
    }
    SessionEvidence::record('dashboard-exercise-result', ['priorPrimary' => $priorAssertion !== null, 'normalReturn' => $actual === null,
        'type' => $actual === null ? null : $actual::class, 'inputPresent' => is_file($evidence->path . '/input-before-first-close/web/test.sqlite'),
        'inputOwnerRetained' => $evidence->hasFailure()]);
    if ($actual !== null) { $evidence->retainFailure($actual); }
    Assert::true(is_file($evidence->path . '/input-before-first-close/web/test.sqlite'), 'The actual original exercise DB input remains retained');
    if ($priorAssertion !== null) { Assert::true($control['expected'] instanceof ComparisonFailure, 'The real original exercise assertion failed before final cleanup'); }
    dashboard_assert_exercise_failure($actual, $control['expected']);
    Assert::true(is_file($evidence->path . '/input-before-first-close/input-manifest.json'), 'The original exercise raw-input manifest remains');
}

/** @param array{listener: resource|null, port: ?int, expected: ?ComparisonFailure} $control
 * @param null|callable(string): void $priorAssertion */
function dashboard_exercise_listener(DashboardModesFixture $fixture, TemporaryDirectory $evidence, ?callable $priorAssertion, array &$control): void
{
    $input = $evidence->path . '/input-before-first-close';
    $database = WeakReference::create($fixture->web->database());
    dashboard_capture_success_input($fixture, $input, retention: 'exercise-control');
    $fixture->close();
    Assert::same($database->get(), null, 'Exercise control releases the actual parent PDO before the final failure');
    foreach ([$fixture->directory->path, $fixture->web->directory->path] as $path) { Assert::false(is_dir($path), 'The first exercise disposal removed its original fixture directory'); }
    $port = dashboard_final_peer_port($evidence);
    Assert::same($port, $fixture->peer->port, 'The exercise control observes its exact real peer port');
    foreach ([$fixture->web->server->port, $port] as $released) { dashboard_released_port($released); }
    $listener = stream_socket_server('tcp://127.0.0.1:' . $port);
    if (!is_resource($listener)) { throw new RuntimeException('Cannot acquire the owned exercise listener'); }
    $control['listener'] = $listener;
    $control['port'] = $port;
    $address = stream_socket_get_name($listener, remote: false);
    Assert::same($address, '127.0.0.1:' . $port, 'The actual exercise listener occupies the released peer port');
    SessionEvidence::record('dashboard-exercise-listener', ['port' => $port, 'address' => $address, 'inputBeforeFailure' => $input,
        'inputDbSha256' => \Tablo\RequiredFile::digest($input . '/web/test.sqlite'), 'priorPrimary' => $priorAssertion !== null]);
    dashboard_expect_rejection(static fn () => dashboard_released_port($port), meaning: 'The exercise finalizer receives the actual occupied peer port');
    SessionEvidence::record('dashboard-exercise-occupied-confirmed', ['port' => $port, 'priorPrimary' => $priorAssertion !== null]);
    if ($priorAssertion === null) { return; }
    try { $priorAssertion($input); }
    catch (ComparisonFailure $primary) { $control['expected'] = $primary; throw $primary; }
}

function dashboard_assert_exercise_failure(?Throwable $actual, ?ComparisonFailure $expected): void
{
    if ($expected !== null) { Assert::same($actual, $expected, 'Exercise finalization preserves the exact original primary'); return; }
    Assert::true($actual instanceof RuntimeException, 'Dashboard exercise must propagate its new final cleanup failure');
    Assert::same(FixtureValue::object($actual)->getMessage(), 'Owned listener remains after closure', 'The exercise caller propagates the actual final resource observation failure');
}
