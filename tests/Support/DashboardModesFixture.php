<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PDO;
use RuntimeException;
use Tablo\SiteInput;
use Tablo\SiteRepository;
use Tablo\SqlStatement;
use Throwable;

/** @phpstan-import-type View from \Tablo\Presenter */
final class DashboardModesFixture
{
    public readonly TemporaryDirectory $directory;
    public readonly WebFixture $web;
    public readonly TestServer $peer;
    public readonly string $csrf;
    public const EPOCH = 1_000_000;

    /** @param null|callable(array<string, string>, TestServer): WebFixture $startWeb */
    public function __construct(?callable $startWeb = null)
    {
        [$this->directory, $this->web, $this->peer, $this->csrf] = open_dashboard_modes($startWeb);
    }

    /** @param callable(self): void $assertions */
    public static function exercise(callable $assertions): void
    {
        $fixture = new self();
        try { $assertions($fixture); }
        catch (Throwable $error) {
            $fixture->web->directory->retainFailure($error);
            $fixture->directory->retainFailure($error);
            throw $error;
        } finally { $fixture->directory->cleanup($fixture->close(...)); }
    }

    public function clock(int $epoch): void
    { write_dashboard_clock($this->web, $epoch); }

    /** @return list<int> */
    public static function seed(PDO $db, ?string $peer = null, string $set = 'matrix'): array
    { return seed_dashboard_modes($db, $peer, $set); }

    /** @return list<DOMElement> */
    public static function elements(string $html, string $selector): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="UTF-8">' . $html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        /** @var \DOMNodeList<\DOMNode>|false $nodes Native XPath query returns nodes or false. */
        $nodes = (new DOMXPath($document))->query($selector);
        if ($nodes === false) { throw new RuntimeException('Invalid DOM selector'); }
        $elements = [];
        foreach ($nodes as $node) { if (!$node instanceof DOMElement) { continue; } $elements[] = $node; }
        return $elements;
    }

    /** @return list<int> */
    public static function ids(string $html, string $view): array
    { return array_map(static fn (DOMElement $element): int => (int) $element->getAttribute('data-site-id'), self::elements($html, '//*[@data-dashboard-view="' . $view . '"]//*[@data-site-id]')); }

    /** @return array{check_history: int, incidents: int, notification_slots: int} */
    public function counts(): array
    {
        $counts = [];
        foreach (['check_history', 'incidents', 'notification_slots'] as $table) {
            $statement = SqlStatement::checked($this->web->database()->query('SELECT COUNT(*) FROM ' . $table));
            try { $counts[$table] = (int) $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
        }
        return ['check_history' => \Tablo\SqlColumn::integer($counts, 'check_history'), 'incidents' => \Tablo\SqlColumn::integer($counts, 'incidents'), 'notification_slots' => \Tablo\SqlColumn::integer($counts, 'notification_slots')];
    }

    public function peerCount(): int
    {
        $file = $this->directory->path . '/requests.jsonl';
        return is_file($file) ? count(FixtureFiles::lines($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    /** @return array<string, int> */
    public function timestamps(): array
    {
        $jar = \Tablo\RequiredFile::bytes(file_get_contents($this->web->directory->path . '/cookies'));
        $match = [];
        if (preg_match('/\ttablo_session\t([a-zA-Z0-9,-]+)\s*$/m', $jar, $match) !== 1) { throw new RuntimeException('Synthetic session missing'); }
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($this->web->directory->path . '/runtime/sessions/sess_' . FixtureValue::string(FixtureValue::at($match, 1))));
        $timestamps = [];
        foreach (['authenticated_at', 'last_activity_at', 'session_observed_at'] as $field) {
            if (preg_match('/' . $field . '\|i:([0-9]+);/', $bytes, $match) !== 1) { throw new RuntimeException('Synthetic timestamp missing'); }
            $timestamps[$field] = (int) FixtureValue::at($match, 1);
        }
        return $timestamps;
    }

    public function close(): void
    { close_dashboard_modes($this->directory, $this->web, $this->peer); }
}

/** @param null|callable(array<string, string>, TestServer): WebFixture $startWeb
 * @return array{TemporaryDirectory, WebFixture, TestServer, string} */
function open_dashboard_modes(?callable $startWeb): array
{
    $directory = null; $web = null; $peer = null;
    try {
        $directory = new TemporaryDirectory('tablo-dashboard-modes-');
        if (file_put_contents($directory->path . '/key', random_bytes(32)) !== 32) {
            throw new RuntimeException('Cannot write the owned dashboard fixture key');
        }
        $peer = new TestServer($directory, dirname(__DIR__) . '/fixtures/endpoint-token-router.php', environment:
            ['TABLO_ENDPOINT_FIXTURE' => $directory->path]);
        $environment = ['TABLO_TEST_SESSION_CLOCK' => '1', 'TABLO_TOKEN_KEY_FILE' => $directory->path . '/key'];
        $web = $startWeb === null ? new WebFixture(true, environment: $environment) : $startWeb($environment, $peer);
        write_dashboard_clock($web, DashboardModesFixture::EPOCH);
        return [$directory, $web, $peer, $web->authenticate()];
    } catch (Throwable $primary) { fail_dashboard_modes($primary, $directory, $web, $peer); }
}

function write_dashboard_clock(WebFixture $web, int $epoch): void
{
    $bytes = (string) $epoch;
    if (file_put_contents($web->directory->path . '/runtime-clock', $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write the owned dashboard fixture clock');
    }
}

/** @return list<TemporaryDirectory> */
function dashboard_directories(?TemporaryDirectory $directory, ?WebFixture $web): array
{
    $directories = [];
    if ($web !== null) { $directories[] = $web->directory; }
    if ($directory !== null) { $directories[] = $directory; }
    return $directories;
}

/** @param list<TemporaryDirectory> $directories */
function dashboard_cleanup_failure(array $directories, Throwable $cleanup): void
{
    foreach ($directories as $directory) {
        try { $directory->recordCleanupFailure($cleanup); }
        catch (Throwable) {
            \Tablo\NativeWarning::ignore(static fn (): bool => error_log('Dashboard fixture evidence unavailable; original failure preserved'));
        }
    }
}

function fail_dashboard_modes(Throwable $primary, ?TemporaryDirectory $directory, ?WebFixture $web, ?TestServer $peer): never
{
    $directories = dashboard_directories($directory, $web);
    foreach ($directories as $owned) {
        try { $owned->retainFailure($primary); }
        catch (Throwable $cleanup) { dashboard_cleanup_failure($directories, $cleanup); }
    }
    try { close_dashboard_modes($directory, $web, $peer); }
    catch (Throwable $cleanup) { dashboard_cleanup_failure($directories, $cleanup); }
    throw $primary;
}

/** @param list<callable(): void> $releases */
function release_dashboard_resources(array $releases): void
{
    $failure = null;
    foreach ($releases as $release) {
        try { $release(); }
        catch (Throwable $error) { $failure ??= $error; }
    }
    if ($failure !== null) { throw $failure; }
}

function dashboard_port_closed(TestServer $server): void
{
    $connection = \Tablo\NativeWarning::ignore(static fn () => fsockopen('127.0.0.1', $server->port, timeout: .1));
    if ($connection !== false) { fclose($connection); throw new RuntimeException('Owned listener remains after closure'); }
}

function close_dashboard_modes(?TemporaryDirectory $directory, ?WebFixture $web, ?TestServer $peer): void
{
    $directories = dashboard_directories($directory, $web);
    $servers = []; $releases = [];
    if ($web !== null) { $releases[] = $web->reopenDatabase(...); $servers[] = $web->server; }
    if ($peer !== null) { $servers[] = $peer; }
    foreach ($servers as $server) { $releases[] = $server->close(...); }
    foreach ($servers as $server) { $releases[] = static fn () => dashboard_port_closed($server); }
    try {
        release_dashboard_resources($releases);
        SessionEvidence::record('dashboard-closure', ['ports' => array_map(static fn (TestServer $server): int => $server->port, $servers),
            'portsAbsent' => true, 'databaseReleased' => $web !== null,
            'fixture' => $web?->directory->path, 'peer' => $directory?->path]);
        // Browser diagnostics and failures retain their directories after actual resource release.
        if (getenv('TABLO_DASHBOARD_RETAIN') === '1') { return; }
        foreach ($directories as $owned) { $owned->close(); }
    } catch (Throwable $cleanup) { dashboard_cleanup_failure($directories, $cleanup); throw $cleanup; }
}

/** @return list<int> */
function seed_dashboard_modes(PDO $db, ?string $peer, string $set): array
    {
        $sites = new SiteRepository($db);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $rows = dashboard_mode_rows($now, $old, $set);
        $ids = [];
        foreach ($rows as [$name, $host, $mode, $enabled, $online, $version, $release, $commit, $latest, $issues, $prs, $ms, $order, $checked, $error, $versionPath, $reason]) {
            $id = $sites->save(array_replace(SiteInput::defaults(), ['name' => $name,
                'url' => $peer ?? 'https://' . $host . '.example.com', 'repository' => 'example/' . $host,
                'comparison_mode' => $mode, 'enabled' => 1, 'sort_order' => $order,
                'version_path' => $versionPath, 'health_path' => '/up?fixture_private_query_marker=1']));
            $ids[] = $id;
            if ($checked === null) { continue; }
            $sites->storeCheck(FixtureValue::row($sites->find($id)), ['online' => $online, 'deployed_version' => $version,
                'latest_release' => $release, 'deployed_commit' => $commit, 'latest_commit' => $latest === null ? null : $latest . str_repeat('0', times: 33),
                'open_issues' => $issues, 'open_prs' => $prs, 'response_time_ms' => $ms, 'last_error' => $error,
                'checked_at' => $checked, 'health_error_code' => $reason, 'health_http_status' => $reason === 'http' ? 503 : null]);
            if ($enabled === 0) {
                // Seed a paused site with its genuine previous result; native paused checks stay refused.
                $statement = SqlStatement::checked($db->prepare('UPDATE sites SET enabled=0 WHERE id=?'));
                try { $statement->execute([$id]); }
                finally { $statement->closeCursor(); }
            }
        }
        return $ids;
    }


/** @return non-empty-list<array{string, string, string, int, ?int, ?string, ?string, ?string, ?string, ?int, ?int, ?int, int, ?string, ?string, string, ?string}> */
function dashboard_mode_rows(string $now, string $old, string $set): array
{
    $rows = [
        ['Storefront', 'store', 'release', 1, 1, '1.3.1', 'v1.3.2', 'a1b2c3d', 'a1b2c3d', 4, 1, 87, 30, $now, null, '/version', null],
        ['Portal', 'portal', 'branch', 1, 1, null, null, 'b2c3d4e', 'b2c3d4e', 7, 0, 42, 10, $now, null, '/version', null],
        ['Documentation', 'docs', 'release', 1, 1, '2.0.0', 'v2.0.0', 'c3d4e5f', 'c3d4e5f', 0, 0, 0, 10, $now, null, '/version', null],
        ['Service API', 'api', 'release', 1, 0, '0.8.0', 'v0.8.0', 'd4e5f6a', 'd4e5f6a', 3, 0, 204, 40, $now, 'Health: HTTP 503 (пример тестового состояния).', '/version', 'http'],
        ['Demo Project', 'demo', 'branch', 0, 1, null, null, 'e5f6a7b', 'e5f6a7b', null, null, null, 50, $old, null, '/version', null],
    ];
    return $set === 'matrix' ? [...$rows, ...dashboard_matrix_rows($now, $old)] : $rows;
}

/** @return non-empty-list<array{string, string, string, int, ?int, ?string, ?string, ?string, ?string, ?int, ?int, ?int, int, ?string, ?string, string, ?string}> */
function dashboard_matrix_rows(string $now, string $old): array
{
    return [
        ['Unchecked', 'unchecked', 'release', 1, null, null, null, null, null, null, null, null, 60, null, null, '/version', null],
        ['Unknown', 'unknown', 'release', 1, null, null, null, null, null, null, null, null, 70, $now, 'Health: соединение отклонено.', '/version', 'refused'],
        ['Partial', 'partial', 'release', 1, 1, '1.0.0', 'v1.0.0', null, null, null, null, 25, 80, $now, 'Git: синтетическая ошибка <script>window.dashboardUnsafe=1</script>', '/version', null],
        ['Newer', 'newer', 'release', 1, 1, '3.0.0', 'v2.0.0', null, null, 1, 0, 1, 90, $old, null, '/version', null],
        ['Arbitrary', 'arbitrary', 'release', 1, 1, 'build-A', 'build-B', null, null, 1, 1, 1, 100, $now, null, '/version', null],
        ['No version', 'no-version', 'release', 1, 1, null, 'v8.0.0', null, 'abcdef1', 0, 0, 0, 110, $now, null, '', null],
        ['Different branch', 'different', 'branch', 1, 1, null, null, 'abcdef1', 'fedcba2', 0, 0, 0, 120, $now, null, '/version', null],

    ];
}
