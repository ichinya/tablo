<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\ServerSchemaDescriptor;
use Tablo\SqlColumn;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\SubprocessReceipt;

/** Fixture administration is available only through the cell's positively owned native client.
 * @phpstan-type Context array{nonce: string, engine: string, execute: \Closure(string, float): string, deadline: float}
 * @phpstan-type Slots array<string, array{phase: string, identity: ?string}>
 * @phpstan-import-type Fixture from ServerFixturePlan
 * @phpstan-import-type Result from Subprocess
 */
final class ServerFixtureCatalog
{
    /** @var Slots */
    private array $slots = [];
    private ?string $active = null;
    private bool $bootstrapAttempted = false;
    /** @var Context */
    private array $context;

    /** @param \Closure(string, float): string $execute */
    public function __construct(string $nonce, string $engine, \Closure $execute)
    {
        ServerFixturePlan::slots($nonce, $engine);
        $this->context = ['nonce' => $nonce, 'engine' => $engine, 'execute' => $execute, 'deadline' => 0.0];
    }

    /** @param array{writer: string, reader: string, migrator: string, deniedDdl: string, metadata: string} $passwords */
    public function bootstrap(#[\SensitiveParameter] array $passwords): void
    {
        if ($this->bootstrapAttempted) { throw new RuntimeException('Fixture account/catalog bootstrap cannot be replayed.'); }
        $this->bootstrapAttempted = true;
        $this->context['deadline'] = hrtime(true) / 1_000_000_000 + 30;
        $this->slots = array_fill_keys(ServerFixturePlan::slots($this->context['nonce'], $this->context['engine']), ['phase' => 'bootstrap-intent', 'identity' => null]);
        $this->slots = server_fixture_catalog_bootstrap($this->context, $passwords);
    }

    /** @param list<Fixture> $fixtures */
    public function begin(string $identity, array $fixtures, float $timeout = 30): void
    {
        if ($this->active !== null) { throw new RuntimeException('Prior fixture case failed or its closure is unknown.'); }
        $this->active = $identity;
        if ($timeout <= 0 || $timeout > 30) { throw new RuntimeException('Finite native fixture setup timeout refused.'); }
        $this->context['deadline'] = hrtime(true) / 1_000_000_000 + $timeout;
        server_fixture_catalog_begin($this->slots, $this->context, $identity, $fixtures);
    }

    /** @param Result $child
     * @param array{cases: array<string, array{status: string, assertions: int, seconds: float}>, assertions: int, platform: string} $native */
    public function complete(array $child, array $native, string $closureDirectory): void
    {
        $identity = $this->active ?? throw new RuntimeException('No active native case to close.');
        server_fixture_catalog_case_closed($identity, $child, $native);
        server_fixture_catalog_descendants_closed($closureDirectory);
        foreach (array_keys($this->slots) as $catalog) {
            if (($this->slots[$catalog]['identity'] ?? null) !== $identity) { continue; }
            $this->slots[$catalog] = ['phase' => 'passed-closed', 'identity' => $identity];
        }
        $this->active = null;
    }

    /** A durable caller records these safe states before and after every stage; no secrets are exposed.
     * @return Slots */
    public function snapshot(): array { return $this->slots; }

    /** @param list<Fixture> $fixtures
     * @return array<string, array{kind: string, tables: int, triggers: int, routines: int, events: int}> */
    public function metadataControls(array $fixtures): array
    {
        return server_fixture_catalog_metadata_controls($this->context, $this->slots, $this->active, $fixtures);
    }
}

/** @param ServerFixtureCatalog::Context $context
 * @param ServerFixtureCatalog::Slots $slots
 * @param list<ServerFixturePlan::Fixture> $fixtures
 * @return array<string, array{kind: string, tables: int, triggers: int, routines: int, events: int}> */
function server_fixture_catalog_metadata_controls(array $context, array $slots, ?string $active, array $fixtures): array
{
    $records = [];
    foreach ($fixtures as $fixture) {
        if ($fixture['engine'] !== $context['engine'] || !str_starts_with($fixture['initial'], 'metadata-')) { continue; }
        if ($active === null || ($slots[$fixture['catalog']]['identity'] ?? null) !== $active
            || ($slots[$fixture['catalog']]['phase'] ?? null) !== 'running') { throw new RuntimeException('Owned active metadata control required.'); }
        $records[$fixture['alias']] = server_fixture_catalog_metadata_control($context, $fixture);
    }
    return $records;
}

/** Administrative setup only, after the ordinary bootstrap child has closed.
 * @param ServerFixtureCatalog::Context $context
 * @param ServerFixturePlan::Fixture $fixture
 * @return array{kind: string, tables: int, triggers: int, routines: int, events: int} */
function server_fixture_catalog_metadata_control(array $context, array $fixture): array
{
    $catalog = $fixture['catalog']; $name = '`' . $catalog . '`.';
    $account = ServerFixturePlan::metadataAccount($context['nonce'], $context['engine'], $catalog);
    $sql = match ($fixture['initial']) {
        'metadata-table' => 'CREATE TABLE ' . $name . 'unexpected_owned_table(id BIGINT PRIMARY KEY) ENGINE=InnoDB;',
        'metadata-view' => 'CREATE VIEW ' . $name . 'unexpected_owned_view AS SELECT 1 AS id;',
        'metadata-trigger' => 'CREATE TRIGGER ' . $name . 'unexpected_owned_trigger BEFORE UPDATE ON ' . $name . 'sites FOR EACH ROW SET NEW.online=1;',
        'metadata-routine' => 'CREATE PROCEDURE ' . $name . 'unexpected_owned_routine() SELECT 1;',
        'metadata-event' => 'CREATE EVENT ' . $name . 'unexpected_owned_event ON SCHEDULE EVERY 1 DAY DISABLE DO SET @tablo_unused_metadata_event=1;',
        'metadata-limited' => 'REVOKE EVENT ON `' . str_replace('_', replace: '\\_', subject: $catalog) . "`.* FROM '" . $account . "'@'%';",
        'metadata-role' => server_fixture_catalog_metadata_role($context, $catalog),
        default => throw new RuntimeException('Unreviewed metadata control refused.'),
    };
    foreach (['TABLES' => 'TABLE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $column) {
        $sql .= "\nSELECT COUNT(*) FROM information_schema." . $table . ' WHERE CAST(' . $column . " AS BINARY) = CAST('" . $catalog . "' AS BINARY);";
    }
    $counts = ['tables' => in_array($fixture['initial'], ['metadata-table', 'metadata-view'], strict: true) ? 19 : 18,
        'triggers' => (int) ($fixture['initial'] === 'metadata-trigger'), 'routines' => (int) ($fixture['initial'] === 'metadata-routine'), 'events' => (int) ($fixture['initial'] === 'metadata-event')];
    if (trim(server_fixture_catalog_execute($context, $sql)) !== implode("\n", array_values($counts))) { throw new RuntimeException('Administrative metadata control counts differ.'); }
    return ['kind' => $fixture['initial']] + $counts;
}

/** @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_metadata_role(array $context, string $catalog): string
{
    $account = ServerFixturePlan::metadataAccount($context['nonce'], $context['engine'], $catalog); $role = 'tr39_' . substr($catalog, offset: 5);
    return $context['engine'] === 'mysql' ? "CREATE ROLE '" . $role . "'@'%'; GRANT '" . $role . "'@'%' TO '" . $account . "'@'%';"
        : 'CREATE ROLE `' . $role . "`; GRANT `" . $role . "` TO '" . $account . "'@'%';";
}

/** @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_metadata_restore(array $context, string $catalog): string
{
    ServerFixturePlan::metadataAccount($context['nonce'], $context['engine'], $catalog); $role = 'tr39_' . substr($catalog, offset: 5);
    $drop = $context['engine'] === 'mysql' ? "DROP ROLE IF EXISTS '" . $role . "'@'%';" : 'DROP ROLE IF EXISTS `' . $role . '`;';
    return $drop . "\n" . server_fixture_catalog_metadata_grant($context, $catalog);
}

/** @param ServerFixtureCatalog::Context $context
 * @param array{writer: string, reader: string, migrator: string, deniedDdl: string, metadata: string} $passwords
 * @return ServerFixtureCatalog::Slots */
function server_fixture_catalog_bootstrap(array $context, #[\SensitiveParameter] array $passwords): array
{
    $accounts = ServerFixturePlan::accounts($context['nonce'], $context['engine']); $sql = [];
    foreach ($accounts as $role => $user) {
        $password = $passwords[$role] ?? throw new RuntimeException('Private fixture role password absent.');
        if (preg_match('/\A[0-9a-f]{64}\z/D', $password) !== 1) { throw new RuntimeException('Private generated fixture password format refused.'); }
        $sql[] = "CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "' REQUIRE SSL;";
    }
    $slots = [];
    foreach (ServerFixturePlan::slots($context['nonce'], $context['engine']) as $catalog) {
        $sql[] = 'CREATE DATABASE `' . $catalog . '` CHARACTER SET utf8mb4;';
        $sql[] = 'GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON `' . $catalog . "`.* TO '" . $accounts['migrator'] . "'@'%';";
        // Test-only actor can maintain durable intent/phase but cannot execute DDL.
        $sql[] = 'GRANT SELECT,INSERT,UPDATE ON `' . $catalog . "`.* TO '" . $accounts['deniedDdl'] . "'@'%';";
        $metadata = ServerFixturePlan::metadataAccount($context['nonce'], $context['engine'], $catalog);
        $password = $passwords['metadata'];
        if (preg_match('/\A[0-9a-f]{64}\z/D', $password) !== 1) { throw new RuntimeException('Private metadata password format refused.'); }
        $sql[] = "CREATE USER '" . $metadata . "'@'%' IDENTIFIED BY '" . $password . "' REQUIRE SSL;";
        $sql[] = server_fixture_catalog_metadata_grant($context, $catalog);
        $slots[$catalog] = ['phase' => 'fresh', 'identity' => null];
    }
    $sql[] = 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN (' . implode(',', array_map(static fn (string $name): string => "'" . $name . "'", array_keys($slots))) . ');';
    if (trim(server_fixture_catalog_execute($context, implode("\n", $sql))) !== '0') { throw new RuntimeException('Fresh catalog bootstrap object union is not empty.'); }
    return $slots;
}

/** Exact literal catalog pattern, including escaped underscore grant semantics.
 * @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_metadata_grant(array $context, string $catalog): string
{
    $user = ServerFixturePlan::metadataAccount($context['nonce'], $context['engine'], $catalog);
    $routine = $context['engine'] === 'mysql' ? 'ALTER ROUTINE' : 'SHOW CREATE ROUTINE';
    return 'GRANT SELECT,TRIGGER,EVENT,' . $routine . ' ON `' . str_replace('_', replace: '\\_', subject: $catalog) . "`.* TO '" . $user . "'@'%';";
}

/** @param ServerFixtureCatalog::Slots $slots
 * @param ServerFixtureCatalog::Context $context
 * @param list<ServerFixturePlan::Fixture> $fixtures */
function server_fixture_catalog_begin(array &$slots, array $context, string $identity, array $fixtures): void
{
    if ($identity === '') { throw new RuntimeException('Native fixture identity absent.'); }
    $seen = [];
    foreach ($fixtures as $fixture) {
        if ($fixture['engine'] !== $context['engine']) { continue; }
        $catalog = $fixture['catalog']; $state = $slots[$catalog] ?? throw new RuntimeException('Catalog was not created by this exact fixture owner.');
        if (array_key_exists($catalog, $seen) || !in_array($state['phase'], ['fresh', 'passed-closed'], strict: true)) { throw new RuntimeException('Unclosed or repeated native fixture slot.'); }
        $seen[$catalog] = true; $slots[$catalog] = ['phase' => 'preparing', 'identity' => $identity];
        server_fixture_catalog_unoccupied($context, $catalog);
        if ($state['phase'] === 'passed-closed') { server_fixture_catalog_reset($context, $catalog); }
        server_fixture_catalog_empty($context, $catalog);
        server_fixture_catalog_execute($context, server_fixture_catalog_metadata_restore($context, $catalog));
        server_fixture_catalog_grant_scaffolds($context, $catalog);
        $slots[$catalog] = ['phase' => 'running', 'identity' => $identity];
    }
}

/** @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_unoccupied(array $context, string $catalog): void
{
    if (!in_array($catalog, ServerFixturePlan::slots($context['nonce'], $context['engine']), strict: true)) { throw new RuntimeException('Exact fixture catalog ownership refused.'); }
    if (trim(server_fixture_catalog_execute($context, "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = '" . $catalog . "' AND ID <> CONNECTION_ID();")) !== '0') {
        throw new RuntimeException('Previous native catalog sessions remain live or unknown.');
    }
}

/** @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_reset(array $context, string $catalog): void
{
    server_fixture_catalog_unoccupied($context, $catalog);
    server_fixture_catalog_execute($context, 'DROP DATABASE `' . $catalog . '`; CREATE DATABASE `' . $catalog . '` CHARACTER SET utf8mb4;');
}

/** @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_empty(array $context, string $catalog): void
{
    if (!in_array($catalog, ServerFixturePlan::slots($context['nonce'], $context['engine']), strict: true)) { throw new RuntimeException('Exact fixture catalog ownership refused.'); }
    if (trim(server_fixture_catalog_execute($context, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $catalog . "';")) !== '0') {
        throw new RuntimeException('Owned native initial catalog is not empty.');
    }
}

/** A grant scaffold has no product types, keys, checks, rows, migration state or receipt.
 * @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_grant_scaffolds(array $context, string $catalog): void
{
    $tables = server_fixture_catalog_tables($context['engine']); $created = []; $sql = [];
    foreach ($tables as $table) {
        $columns = array_map(static fn (array $column): string => '`' . $column['name'] . '` BIGINT NULL', $table['columns']);
        $sql[] = 'CREATE TABLE `' . $catalog . '`.`' . $table['name'] . '` (' . implode(',', $columns) . ') ENGINE=InnoDB;';
        $created[] = $table['name'];
    }
    $sql[] = server_fixture_catalog_grants($catalog, ServerFixturePlan::accounts($context['nonce'], $context['engine']));
    foreach (array_reverse($created) as $name) { $sql[] = 'DROP TABLE `' . $catalog . '`.`' . $name . '`;'; }
    // The native client stops at its first error. Partial DDL is retained; this is never a rollback claim.
    server_fixture_catalog_execute($context, implode("\n", $sql));
    if (trim(server_fixture_catalog_execute($context, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $catalog . "';")) !== '0') {
        throw new RuntimeException('Grant-only scaffold removal did not leave an empty catalog.');
    }
}

/** Every SQL child consumes the same finite setup deadline; individual calls never replenish it.
 * @param ServerFixtureCatalog::Context $context */
function server_fixture_catalog_execute(array $context, #[\SensitiveParameter] string $sql): string
{
    $remaining = $context['deadline'] - hrtime(true) / 1_000_000_000;
    if ($remaining <= 0) { throw new RuntimeException('Native fixture setup deadline exceeded.'); }
    $result = ($context['execute'])($sql, $remaining);
    if (hrtime(true) / 1_000_000_000 > $context['deadline']) { throw new RuntimeException('Native fixture setup deadline exceeded.'); }
    return $result;
}

/** @return list<\Tablo\ServerSchema::Table> */
function server_fixture_catalog_tables(string $engine): array
{
    if (!in_array($engine, ['mysql', 'mariadb'], strict: true)) { throw new RuntimeException('Exact fixture engine absent.'); }
    $layout = ServerSchemaDescriptor::forEngine($engine);
    if (count($layout['tables']) !== 18) { throw new RuntimeException('Fixed eighteen scaffold names absent.'); }
    foreach ($layout['tables'] as $table) {
        foreach ([$table['name'], ...array_column($table['columns'], 'name')] as $identifier) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $identifier) !== 1) { throw new RuntimeException('Fixed native scaffold identifier refused.'); }
        }
    }
    return $layout['tables'];
}

/** @param array{writer: string, reader: string, migrator: string, deniedDdl: string} $accounts */
function server_fixture_catalog_grants(string $catalog, array $accounts): string
{
    $database = '`' . $catalog . '`'; $writer = "'" . $accounts['writer'] . "'@'%'"; $reader = "'" . $accounts['reader'] . "'@'%'"; $sql = [];
    foreach (\Tablo\DatabaseTransferManifest::TABLES as $table) { $sql[] = 'GRANT SELECT,INSERT,UPDATE,DELETE ON ' . $database . '.`' . $table . '` TO ' . $writer . ';'; }
    $sql[] = 'GRANT SELECT ON ' . $database . '.tablo_database_state TO ' . $writer . ';';
    $sql[] = 'GRANT UPDATE (committed_history_high_watermark) ON ' . $database . '.tablo_database_state TO ' . $writer . ';';
    $sql[] = 'GRANT SELECT ON ' . $database . '.tablo_migration_journal TO ' . $writer . ';';
    $sql[] = 'GRANT SELECT,UPDATE ON ' . $database . '.tablo_writer_guard TO ' . $writer . ';';
    $sql[] = 'GRANT SELECT (id,password_hash) ON ' . $database . '.users TO ' . $reader . ';';
    $sql[] = 'GRANT SELECT ON ' . $database . '.read_api_token TO ' . $reader . ';';
    $sql[] = 'GRANT SELECT (id,format,logical_version,layout_checksum,phase,installation_id,committed_history_high_watermark) ON ' . $database . '.tablo_database_state TO ' . $reader . ';';
    $sql[] = 'GRANT SELECT (id,config_revision,name,url,provider,repository,branch,enabled,sort_order,online,health_http_status,response_time_ms,open_issues,open_prs,health_path,version_path,comparison_mode,health_check_mode,created_at,updated_at,checked_at,health_error_code,deployed_version,latest_release,deployed_commit,latest_commit) ON ' . $database . '.sites TO ' . $reader . ';';
    return implode("\n", $sql);
}

/** @param Subprocess::Result $child
 * @param array{cases: array<string, array{status: string, assertions: int, seconds: float}>, assertions: int, platform: string} $native */
function server_fixture_catalog_case_closed(string $identity, array $child, array $native): void
{
    $receipt = SubprocessReceipt::read($child['files']['receipt']);
    if ([$child['exit_code'], $receipt['exit_code'], $receipt['closed'], $receipt['root_exit_observed']] !== [0, 0, true, true]
        || $receipt['pid'] === null || $receipt['pid'] < 1
        || array_keys($native['cases']) !== [$identity] || ($native['cases'][$identity]['status'] ?? null) !== 'passed'
        || ($native['cases'][$identity]['assertions'] ?? 0) < 1) { throw new RuntimeException('Native case outcome or exact direct-child closure is unknown.'); }
}

/** All descendants must be captured in this case's new private sink before any slot is reusable. */
function server_fixture_catalog_descendants_closed(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) { throw new RuntimeException('Native case closure sink absent.'); }
    $records = server_fixture_catalog_closure_records($directory);
    $starts = $records['starts']; $closes = $records['closes'];
    if (count($starts) !== count($closes)) { throw new RuntimeException('Native HTTP child closure union is incomplete.'); }
    foreach ($starts as $start) { server_fixture_catalog_http_closed($start, $closes); }
    server_fixture_catalog_direct_union($directory, $records['children'], $records['childCloses']);
}

/** @return array{starts: list<array>, closes: list<array>, children: list<array>, childCloses: list<array>} */
function server_fixture_catalog_closure_records(string $directory): array
{
    $starts = []; $closes = []; $children = []; $childCloses = [];
    foreach (new \DirectoryIterator($directory) as $entry) {
        if ($entry->isLink()) { throw new RuntimeException('Linked native closure evidence refused.'); }
        if ($entry->isDot() || !$entry->isFile() || !str_ends_with($entry->getFilename(), '.json')) { continue; }
        $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($entry->getPathname())));
        if (str_starts_with($entry->getFilename(), 'server-start-')) { $starts[] = $record; }
        if (str_starts_with($entry->getFilename(), 'server-close-')) { $closes[] = $record; }
        if (str_starts_with($entry->getFilename(), 'direct-child-start-')) { $children[] = $record; }
        if (str_starts_with($entry->getFilename(), 'direct-child-close-')) { $childCloses[] = $record; }
        if (str_starts_with($entry->getFilename(), 'child-capture-')) { server_fixture_catalog_captured_child($directory, $record); }
    }
    return ['starts' => $starts, 'closes' => $closes, 'children' => $children, 'childCloses' => $childCloses];
}

/** PID reuse cannot pair unrelated children: every pair also has its unique original receipt path.
 * @param list<array> $starts
 * @param list<array> $closes */
function server_fixture_catalog_direct_union(string $directory, array $starts, array $closes): void
{
    if (count($starts) !== count($closes)) { throw new RuntimeException('Native nested direct-child closure union is incomplete.'); }
    foreach ($starts as $start) {
        $matches = array_values(array_filter($closes, static fn (array $close): bool => [$close['pid'] ?? null, $close['sourceReceipt'] ?? null]
            === [$start['pid'] ?? null, $start['sourceReceipt'] ?? null]));
        if (count($matches) !== 1) { throw new RuntimeException('Native direct-child closure ownership is ambiguous.'); }
        $record = $matches[0];
        server_fixture_catalog_direct_receipt($directory, $record);
    }
}

function server_fixture_catalog_direct_receipt(string $directory, array $record): void
{
    $copy = SqlColumn::string($record, 'receiptCopy');
    if (dirname($copy) !== $directory || is_link($copy) || RequiredFile::digest($copy) !== SqlColumn::string($record, 'receiptSha256')) {
        throw new RuntimeException('Native direct-child closed receipt identity changed.');
    }
    $receipt = SubprocessReceipt::read($copy);
    if ($receipt['pid'] !== SqlColumn::integer($record, 'pid') || !server_fixture_catalog_direct_stopped($receipt)) { throw new RuntimeException('Native direct-child closed receipt outcome is unknown.'); }
}

/** @param list<array> $closes */
function server_fixture_catalog_http_closed(array $start, array $closes): void
{
    $matches = array_values(array_filter($closes, static fn (array $close): bool => [$close['pid'] ?? null, $close['argv'] ?? null] === [$start['pid'] ?? null, $start['argv'] ?? null]));
    if (count($matches) !== 1 || !server_fixture_catalog_http_stopped($matches[0], SqlColumn::integer($start, 'pid'))) { throw new RuntimeException('Native HTTP child closure remains unknown.'); }
}

function server_fixture_catalog_captured_child(string $directory, array $record): void
{
    $prefix = SqlColumn::string($record, 'prefix');
    if (dirname($prefix) !== $directory || is_link($prefix . '.receipt')) { throw new RuntimeException('Captured native child ownership refused.'); }
    $receipt = SubprocessReceipt::read($prefix . '.receipt');
    if (!server_fixture_catalog_direct_stopped($receipt)) { throw new RuntimeException('Captured native descendant closure remains unknown.'); }
    foreach (['stdout', 'stderr'] as $stream) {
        $path = $prefix . '.' . $stream;
        $selected = $stream === 'stdout' ? $receipt['streams']['stdout'] : $receipt['streams']['stderr'];
        if (is_link($path) || RequiredFile::digest($path) !== SqlColumn::string($record, $stream . 'Sha256')
            || RequiredFile::digest($path) !== $selected['sha256']) { throw new RuntimeException('Captured native child stream identity changed.'); }
    }
}


/** Closure is distinct from the native case success gate, which still requires actual exit0 and nonzero passed assertions. */
function server_fixture_catalog_direct_stopped(array $receipt): bool
{
    if (($receipt['closed'] ?? null) !== true || ($receipt['root_exit_observed'] ?? null) !== true
        || !is_int($receipt['pid'] ?? null) || $receipt['pid'] < 1) { return false; }
    return is_int($receipt['exit_code'] ?? null) || server_fixture_catalog_signal_stopped($receipt);
}

/** Only actual signal facts from the Linux observer qualify an unavailable numeric exit. */
function server_fixture_catalog_signal_stopped(array $receipt): bool
{
    return ($receipt['platform'] ?? null) === 'Linux' && ($receipt['signaled'] ?? null) === true
        && is_int($receipt['term_signal'] ?? null) && $receipt['term_signal'] > 0 && is_int($receipt['proc_close'] ?? null);
}

function server_fixture_catalog_http_stopped(array $record, int $pid): bool
{
    if (($record['closed'] ?? null) !== true || $pid < 1) { return false; }
    if (server_fixture_catalog_http_numeric_exit($record)) { return true; }
    if (($record['observedPid'] ?? null) !== $pid || ($record['rootExitObserved'] ?? null) !== true) { return false; }
    return server_fixture_catalog_signal_stopped(['platform' => $record['platform'] ?? null, 'signaled' => $record['signaled'] ?? null,
        'term_signal' => $record['termSignal'] ?? null, 'proc_close' => $record['procClose'] ?? null]);
}

function server_fixture_catalog_http_numeric_exit(array $record): bool
{
    return is_int($record['observedExit'] ?? null) || (is_int($record['procClose'] ?? null) && $record['procClose'] !== -1);
}
