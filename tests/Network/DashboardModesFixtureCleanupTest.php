<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use RuntimeException;
use Tablo\Tests\Support\DashboardModesFixture;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\SessionEvidence;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
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
}

function dashboard_success_control(): void
{
    $fixture = new DashboardModesFixture();
    $paths = [$fixture->directory->path, $fixture->web->directory->path];
    $ports = [$fixture->peer->port, $fixture->web->server->port];
    $database = WeakReference::create($fixture->web->database());
    try { $fixture->close(); }
    finally { $fixture->directory->cleanup($fixture->close(...)); }
    Assert::same($database->get(), null, 'The actual parent PDO reference was released');
    foreach ($paths as $path) { Assert::false(is_dir($path), 'Successful cleanup removes owned fixture data'); }
    foreach ($ports as $port) { dashboard_released_port($port); }
}

function dashboard_failure_control(string $stage): void
{
    $evidence = new TemporaryDirectory('tablo-dashboard-startup-evidence-');
    $previous = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $evidence->path);
    /** @var array{peer: ?TestServer, web: ?WebFixture, database: WeakReference<PDO>|null, nativePrimary: ?Throwable, fixture: ?DashboardModesFixture} $resources */
    $resources = ['peer' => null, 'web' => null, 'database' => null, 'nativePrimary' => null, 'fixture' => null];
    $testError = null;
    try {
        $primary = dashboard_capture_failure($stage, $resources);
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

/** @param array{peer: ?TestServer, web: ?WebFixture, database: WeakReference<PDO>|null, nativePrimary: ?Throwable, fixture: ?DashboardModesFixture} $resources */
function dashboard_capture_failure(string $stage, array &$resources): Throwable
{
    try {
        $resources['fixture'] = new DashboardModesFixture(static function (array $environment, TestServer $startedPeer) use ($stage, &$resources): WebFixture {
            $resources['peer'] = $startedPeer;
            if ($stage === 'startup') {
                // Native socket parsing fails before a second child can start; the peer is real.
                try { return new WebFixture(true, host: "127.0.0.1\0", environment: $environment); }
                catch (Throwable $error) { $resources['nativePrimary'] = $error; throw $error; }
            }
            $web = new WebFixture(true, router: dirname(__DIR__) . '/fixtures/dashboard-modes-startup-failure.php', environment: $environment);
            $resources['web'] = $web;
            \Tablo\Tests\Support\write_dashboard_clock($web, DashboardModesFixture::EPOCH);
            Assert::same($web->request('/setup')['status'], 200);
            $resources['database'] = WeakReference::create($web->database());
            return $web;
        });
    } catch (Throwable $error) { return $error; }
    throw new RuntimeException('Controlled startup or authentication refusal did not occur');
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
    Assert::true(is_int($receipt['observedExit'] ?? null) || (is_int($receipt['procClose'] ?? null) && $receipt['procClose'] !== -1), 'A native direct-child exit was observed');
    $path = \Tablo\SqlColumn::string($start, 'fixture');
    Assert::true(is_dir($path), 'Failed input directories remain after process closure');
    Assert::true(is_file($path . '/server.stderr'), 'Original server diagnostics are retained');
    $failures = dashboard_control_records($path, kind: 'failure');
    Assert::true(count(array_filter($failures, static fn (array $record): bool => ($record['kind'] ?? null) === 'primary' && ($record['type'] ?? null) === $primary::class)) > 0, 'The common sanitized primary-failure record is retained');
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

/** @param array{peer: ?TestServer, web: ?WebFixture, database: WeakReference<PDO>|null, nativePrimary: ?Throwable, fixture: ?DashboardModesFixture} $resources */
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
    $errno = 0; $error = '';
    $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
    Assert::true(is_resource($socket), 'The owned port can be rebound after the real child closes');
    if (is_resource($socket)) { fclose($socket); }
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

function dashboard_copy_control(string $source, string $target): void
{
    if (!mkdir($target, permissions: 0o700, recursive: true) && !is_dir($target)) { throw new RuntimeException('Cannot retain owned startup evidence'); }
    foreach (new \DirectoryIterator($source) as $file) {
        if ($file->isDot()) { continue; }
        if ($file->isLink()) { throw new RuntimeException('Linked startup evidence is unsupported'); }
        $destination = $target . '/' . $file->getFilename();
        if ($file->isDir()) { dashboard_copy_control($file->getPathname(), $destination); continue; }
        if (!copy($file->getPathname(), $destination)) { throw new RuntimeException('Cannot copy owned startup evidence'); }
    }
}
