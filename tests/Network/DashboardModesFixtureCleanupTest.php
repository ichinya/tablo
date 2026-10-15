<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use RuntimeException;
use Tablo\Tests\Support\DashboardModesFixture;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\SessionEvidence;
use Tablo\Tests\Support\Subprocess;
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
    try {
        $snapshot = dashboard_success_snapshot($fixture);
        $fixture->close();
        Assert::same($database->get(), null, 'The actual parent PDO reference was released');
        foreach ($paths as $path) { Assert::false(is_dir($path), 'Successful cleanup removes owned fixture data'); }
        foreach ($ports as $port) { dashboard_released_port($port); }
        if ($afterDisposal !== null) { $afterDisposal($snapshot); }
    }
    catch (Throwable $error) {
        $snapshot?->retainFailure($error);
        $fixture->directory->retainFailure($error); $fixture->web->directory->retainFailure($error); throw $error;
    }
    finally { dashboard_finish_success($fixture, $snapshot); }
}

function dashboard_finish_success(DashboardModesFixture $fixture, ?TemporaryDirectory $snapshot): void
{
    try { $fixture->directory->cleanup($fixture->close(...)); }
    catch (Throwable $cleanup) { $snapshot?->recordCleanupFailure($cleanup); throw $cleanup; }
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
        $command = [$system . '/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-File', $script, '-Port', (string) $port];
        $result = Subprocess::run($command, $directory, environment: []);
        if ($result['exit_code'] !== 0 || $result['stderr'] !== '') { throw new RuntimeException('Windows port observer did not complete cleanly'); }
        $observation = \Tablo\JsonRecord::decode($result['stdout'], 32);
        $available = dashboard_windows_observation($observation, $port);
        $receipt = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($result['files']['receipt'])), 32);
        dashboard_observer_closed($receipt, $observation);
        dashboard_write_manifest($directory->path . '/observation.json', ['port' => $port, 'observation' => $observation,
            'script_sha256' => $scriptHash]);
        SessionEvidence::record('dashboard-port-observation', ['port' => $port, 'observation' => $observation]);
        Assert::true($available, 'The Windows kernel grants exclusive use of the owned port');
    } catch (Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->cleanup(static fn () => dashboard_finish_observer($directory)); }
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
