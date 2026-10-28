<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseConfiguration;
use Tablo\DatabaseConnectionFactory;
use Tablo\DatabaseFailure;
use Tablo\ServerMetadataAdmission;
use Tablo\ServerMetadataIdentity;
use Tablo\ServerPdoConnection;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineMetadataAdmissionTest
{
    #[Test]
    public function hiddenTableRefusesBeforeTheFixedGrantWriterCanMutate(): void
    { engine_metadata_hidden('metadata-table', counter: 'tables', expected: 20); }

    #[Test]
    public function hiddenViewRefusesBeforeTheFixedGrantWriterCanMutate(): void
    { engine_metadata_hidden('metadata-view', counter: 'tables', expected: 20); }

    #[Test]
    public function triggerRefusesEvenWhenTheMysqlWriterCannotSeeItsRow(): void
    { engine_metadata_hidden('metadata-trigger', counter: 'triggers', expected: 1); }

    #[Test]
    public function inertRoutineRefusesWithoutReadingOrExecutingItsBody(): void
    { engine_metadata_hidden('metadata-routine', counter: 'routines', expected: 1); }

    #[Test]
    public function disabledEventRefusesWithoutDependingOnSchedulerState(): void
    { engine_metadata_hidden('metadata-event', counter: 'events', expected: 1); }

    #[Test]
    public function revokedMetadataVisibilityRefusesRatherThanTreatingFilteredRowsAsAbsent(): void
    { engine_metadata_hidden('metadata-limited', counter: 'tables', expected: 19); }

    #[Test]
    public function missingMetadataCredentialsNeverFallBackToWriterOrMigrator(): void
    { engine_metadata_missing(); }

    #[Test]
    public function anotherOwnedCatalogMetadataIdentityCannotAttestTheSelectedCatalog(): void
    { engine_metadata_cross_catalog(); }

    #[Test]
    public function completeObservationAdmitsTheUnchangedExactWriterGrantPolicy(): void
    { engine_metadata_positive(); }

    #[Test]
    public function evenAnInactiveGrantedRoleRefusesTheDirectMetadataProfile(): void
    { engine_metadata_hidden('metadata-role', counter: 'tables', expected: 19); }
}

function engine_metadata_hidden(string $case, string $counter, int $expected): void
{
    $fixture = new EngineFixture($case);
    try {
        $configuration = DatabaseConfiguration::fromEnvironment(environment: $fixture->environment());
        $db = ServerPdoConnection::open($configuration);
        $before = engine_metadata_snapshot($db);
        $record = engine_metadata_control_record($fixture, $configuration);
        Assert::same($record['kind'] ?? null, $case); Assert::same($record[$counter] ?? null, $expected);
        if (in_array($case, ['metadata-table', 'metadata-view'], strict: true)) {
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', $db), 19);
        }
        if ($case === 'metadata-trigger') {
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()', $db), $configuration->engine === 'mysql' ? 0 : 1);
        }
        if ($case === 'metadata-role') {
            $metadata = ServerPdoConnection::open($configuration->metadata());
            try {
                Assert::same($fixture->scalar('SELECT COUNT(*) FROM information_schema.APPLICABLE_ROLES', $metadata), 1);
                Assert::same($fixture->scalar('SELECT COUNT(*) FROM information_schema.ENABLED_ROLES WHERE ROLE_NAME IS NOT NULL', $metadata), 0);
            } finally { $metadata = null; }
        }
        engine_metadata_refuses($fixture->environment(), category: 'schema');
        Assert::same(engine_metadata_snapshot($db), $before);
        Assert::same(engine_metadata_control_record($fixture, $configuration), $record);
        Assert::false($db->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($db));
    } finally { $fixture->close(); }
}

function engine_metadata_missing(): void
{
    $fixture = new EngineFixture('metadata-missing');
    try {
        $db = $fixture->database(); $before = engine_metadata_snapshot($db);
        foreach (['TABLO_DB_METADATA_USER', 'TABLO_DB_METADATA_PASSWORD'] as $field) {
            $environment = $fixture->environment(); unset($environment[$field]);
            engine_metadata_refuses($environment, category: 'configuration');
        }
        Assert::same(engine_metadata_snapshot($db), $before);
    } finally { $fixture->close(); }
}

function engine_metadata_cross_catalog(): void
{
    $fixture = new EngineFixture('metadata-cross'); $peer = new EngineFixture('metadata-cross-peer');
    try {
        $db = $fixture->database(); $other = $peer->database();
        $before = engine_metadata_snapshot($db); $peerBefore = engine_metadata_snapshot($other);
        $peerObservation = ServerMetadataAdmission::observe(DatabaseConfiguration::fromEnvironment(environment: $peer->environment()), 11);
        $binding = null;
        try { ServerMetadataAdmission::bind($db, $peerObservation); } catch (DatabaseFailure $error) { $binding = $error; }
        Assert::same($binding?->category, 'schema'); Assert::same($binding?->getPrevious(), null);
        $environment = $fixture->environment(); $environment['TABLO_DB_METADATA_USER'] = $peer->environment()['TABLO_DB_METADATA_USER'] ?? throw new \RuntimeException('Owned peer metadata identity absent.');
        // The peer metadata account cannot select this catalog; connection denial precedes profile admission.
        engine_metadata_refuses($environment, category: 'unavailable');
        Assert::same(engine_metadata_snapshot($db), $before); Assert::same(engine_metadata_snapshot($other), $peerBefore);
    } finally { $fixture->close(); $peer->close(); }
}

function engine_metadata_positive(): void
{
    $fixture = new EngineFixture('metadata-positive');
    try {
        $configuration = DatabaseConfiguration::fromEnvironment(environment: $fixture->environment());
        $observation = ServerMetadataAdmission::observe($configuration, 11);
        if ($configuration->engine === 'mariadb') {
            $metadata = ServerPdoConnection::open($configuration->metadata());
            try {
                Assert::same(ServerSchema::rows($metadata, 'SELECT ROLE_NAME FROM information_schema.ENABLED_ROLES'), [['ROLE_NAME' => null]]);
                Assert::same($fixture->scalar('SELECT COUNT(*) FROM information_schema.APPLICABLE_ROLES', $metadata), 0);
            } finally { $metadata = null; }
        }
        Assert::same(count($observation['objects']), 19); Assert::same($observation['server']['catalog'] ?? null, $configuration->catalog);
        $db = $fixture->database();
        engine_metadata_writer_grants($db, $configuration);
        engine_metadata_journal_checks($fixture, $db, $configuration);
        engine_metadata_invocation_tables($db, $configuration, $observation);
        engine_metadata_scoped_layout($fixture, $db, $configuration, $observation);
        $sites = new \Tablo\SiteRepository($db, $fixture->vault);
        Assert::same($sites->save(\Tablo\Tests\Support\UnitFixtures::site()), 1);
        $watermark = $fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state', $db);
        Assert::true(\Tablo\Tests\Support\IncidentFixtures::accept($sites, 1, online: 1, time: '0001-01-01T00:00:00Z'));
        Assert::true($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state', $db) > $watermark);
        $admitted = $fixture->independent();
        ServerMetadataAdmission::bind($admitted, $observation);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history', $admitted), 1);
        Assert::same($fixture->nativeFailure('CREATE TABLE forbidden_writer_table(id BIGINT)'), 1142);
        Assert::same(count(ServerSchema::objects($db)), 19);
    } finally { $fixture->close(); }
}

/** @param \Tablo\ServerMetadataAdmission::Observation $observation */
function engine_metadata_invocation_tables(\PDO $db, DatabaseConfiguration $configuration, array $observation): void
{
    $tables = ServerSchema::layout($db)['tables']; $before = $tables;
    $provided = ServerMetadataAdmission::observe($configuration, version: 11, expectedTables: $tables);
    Assert::same(engine_metadata_sorted_observation($provided), engine_metadata_sorted_observation($observation));
    Assert::same($tables, $before);
    if ($configuration->engine === 'mariadb') {
        $tail = $tables[17] ?? throw new \RuntimeException('Missing historical API table.');
        $check = $tail['checks'][3] ?? throw new \RuntimeException('Missing final public CHECK.');
        Assert::same([$tail['name'], $check['name']], ['read_api_token', 'read_api_token_c4']);
        $fingerprint = hash('sha256', "tablo/check-canonical/v1\0" . \Tablo\ServerExpression::canonical($check['expression']));
        Assert::same($observation['checks']['read_api_token']['read_api_token_c4'] ?? null, $fingerprint);
        Assert::same($provided['checks']['read_api_token']['read_api_token_c4'] ?? null, $fingerprint);
    }
    // Borrowed observations exercise historical name rules, never actual predecessor catalogs.
    $withoutApi = $provided;
    $withoutApi['objects'] = array_values(array_filter($provided['objects'], static fn (array $object): bool => !in_array($object['name'], ['read_api_token', 'self_update'], strict: true)));
    $ten = $provided; $ten['objects'] = array_values(array_filter($provided['objects'], static fn (array $object): bool => $object['name'] !== 'self_update'));
    Assert::same([count($withoutApi['objects']), count($ten['objects']), count($provided['objects'])], [17, 18, 19]);
    $missing = $provided;
    $missing['objects'] = array_values(array_filter($provided['objects'], static fn (array $object): bool => $object['name'] !== 'users'));
    $extra = $provided; $extra['objects'][] = ['name' => 'zz_source96_unexpected', 'kind' => 'BASE TABLE'];
    foreach ([null, $tables] as $expectedTables) {
        engine_metadata_expected_case($db, $provided, version: 11, expectedTables: $expectedTables);
        engine_metadata_expected_case($db, $withoutApi, version: 9, expectedTables: $expectedTables);
        engine_metadata_expected_case($db, $ten, version: 10, expectedTables: $expectedTables);
        foreach ([9, 11] as $version) { engine_metadata_expected_case($db, $ten, $version, $expectedTables, category: 'schema'); }
        foreach ([0, 11] as $version) { engine_metadata_expected_case($db, $withoutApi, $version, $expectedTables, category: 'schema'); }
        engine_metadata_expected_case($db, $missing, version: 11, expectedTables: $expectedTables, category: 'schema');
        engine_metadata_expected_case($db, $extra, version: 11, expectedTables: $expectedTables, category: 'schema');
    }
}

/** CHECK rows have no SQL ordering contract; all observation values still compare exactly.
 * @param \Tablo\ServerMetadataAdmission::Observation $observation
 * @return \Tablo\ServerMetadataAdmission::Observation */
function engine_metadata_sorted_observation(array $observation): array
{
    foreach ($observation['checks'] as $name => $clauses) { ksort($clauses, SORT_STRING); $observation['checks'][$name] = $clauses; }
    return $observation;
}

/** @param \Tablo\ServerMetadataAdmission::Observation $observation
 * @param list<\Tablo\ServerSchema::Table>|null $expectedTables */
function engine_metadata_expected_case(\PDO $db, array $observation, int $version, ?array $expectedTables, ?string $category = null): void
{
    $session = \Tablo\DatabaseSession::get($db) ?? throw new \RuntimeException('Missing admitted session.');
    $prior = $session->metadataObservation; $session->metadataObservation = $observation;
    try {
        $caught = null; $names = null;
        try { $names = ServerMetadataAdmission::objects($db, $version, expectedTables: $expectedTables); }
        catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($session->metadataObservation, $observation);
        if ($category === null) {
            Assert::same($caught, null); Assert::same($names, array_column($observation['objects'], 'name'));
            return;
        }
        Assert::same($caught?->category, $category); Assert::same($caught?->getPrevious(), null);
    } finally { $session->metadataObservation = $prior; }
}

/** @param \Tablo\ServerMetadataAdmission::Observation $observation */
function engine_metadata_scoped_layout(EngineFixture $fixture, \PDO $writer, DatabaseConfiguration $configuration, array $observation): void
{
    if ($configuration->engine !== 'mariadb') { return; }
    foreach ([$writer, $fixture->independent('control')] as $connection) {
        $session = \Tablo\DatabaseSession::get($connection) ?? throw new \RuntimeException('Missing admitted session.');
        Assert::same($session->metadataObservation, null);
        ServerSchema::validateLayout($connection);
        Assert::same($session->metadataObservation, null, 'fresh layout observations stay scoped to one admission');
        ServerSchema::validateLayout($connection);
        Assert::same($session->metadataObservation, null, 'sequential admissions each release their fresh observation');
        Assert::false($connection->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($connection));
    }
    engine_metadata_borrowed_layout($writer, $observation);
    engine_metadata_owned_layout_failure($fixture, $writer, $configuration, $observation);
}

/** @param \Tablo\ServerMetadataAdmission::Observation $observation */
function engine_metadata_borrowed_layout(\PDO $db, array $observation): void
{
    $session = \Tablo\DatabaseSession::get($db) ?? throw new \RuntimeException('Missing admitted session.');
    $session->metadataObservation = $observation;
    try {
        ServerSchema::validateLayout($db); Assert::same($session->metadataObservation, $observation);
        $foreign = $observation; $foreign['server']['catalog'] = 'foreign'; $session->metadataObservation = $foreign;
        $caught = null;
        try { ServerSchema::validateLayout($db); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::same($session->metadataObservation, $foreign, 'a refused borrowed observation remains owned by its caller');
    } finally { $session->metadataObservation = null; }
}

/** @param \Tablo\ServerMetadataAdmission::Observation $observation */
function engine_metadata_owned_layout_failure(EngineFixture $fixture, \PDO $db, DatabaseConfiguration $configuration, array $observation): void
{
    $session = \Tablo\DatabaseSession::get($db) ?? throw new \RuntimeException('Missing admitted session.');
    $tail = ServerSchema::layout($db)['tables'][17] ?? throw new \RuntimeException('Missing historical API table.');
    $check = $tail['checks'][3] ?? throw new \RuntimeException('Missing final public CHECK.');
    Assert::same([$tail['name'], $check['name']], ['read_api_token', 'read_api_token_c4']);
    $maintenance = $fixture->maintenance();
    foreach ([['table' => 'tablo_database_state', 'constraint' => 'tablo_database_state_c3',
        'original' => 'logical_version BETWEEN 0 AND 11', 'replacement' => 'logical_version BETWEEN 0 AND 12'],
        ['table' => 'read_api_token', 'constraint' => 'read_api_token_c4',
            'original' => $check['expression'], 'replacement' => 'OCTET_LENGTH(`created_at`) >= 0']] as $change) {
        $changed = false;
        try {
            $maintenance->exec('ALTER TABLE ' . $change['table'] . ' DROP CONSTRAINT ' . $change['constraint']
                . ', ADD CONSTRAINT ' . $change['constraint'] . ' CHECK (' . $change['replacement'] . ')');
            $changed = true;
            $observed = ServerMetadataAdmission::observe($configuration, 11);
            $before = $observation['checks'][$change['table']] ?? throw new \RuntimeException('Missing admitted CHECKs.');
            $after = $observed['checks'][$change['table']] ?? throw new \RuntimeException('Missing observed CHECKs.');
            Assert::same(count($after), count($before));
            Assert::same($after[$change['constraint']] ?? null,
                hash('sha256', "tablo/check-canonical/v1\0" . \Tablo\ServerExpression::canonical($change['replacement'])));
            Assert::false($after[$change['constraint']] === ($before[$change['constraint']] ?? null));
            engine_metadata_changed_layout_refusal($db);
            if ($change['table'] === 'read_api_token') { engine_metadata_changed_layout_refusal($db, $observed); }
        } finally {
            $session->metadataObservation = null;
            if ($changed) {
                $maintenance->exec('ALTER TABLE ' . $change['table'] . ' DROP CONSTRAINT ' . $change['constraint']
                    . ', ADD CONSTRAINT ' . $change['constraint'] . ' CHECK (' . $change['original'] . ')');
            }
        }
        ServerSchema::validateLayout($db); Assert::same($session->metadataObservation, null);
    }
}

/** @param \Tablo\ServerMetadataAdmission::Observation|null $borrowed */
function engine_metadata_changed_layout_refusal(\PDO $db, ?array $borrowed = null): void
{
    $session = \Tablo\DatabaseSession::get($db) ?? throw new \RuntimeException('Missing admitted session.');
    Assert::same($session->metadataObservation, null);
    if ($borrowed !== null) { $session->metadataObservation = $borrowed; }
    $caught = null;
    try { ServerSchema::validateLayout($db); } catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    Assert::same($session->metadataObservation, $borrowed, $borrowed === null
        ? 'later CHECK admission failure clears the locally owned observation'
        : 'the altered final CHECK leaves its borrowed observation unchanged');
    Assert::false($db->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($db));
}

function engine_metadata_journal_checks(EngineFixture $fixture, \PDO $writer, DatabaseConfiguration $configuration): void
{
    if ($configuration->engine !== 'mariadb') { return; }
    $journal = ServerSchema::layout($writer)['tables'][0] ?? throw new \RuntimeException('Missing journal descriptor.');
    foreach (['writer' => $writer, 'migrator' => $fixture->maintenance()] as $role => $connection) {
        \Tablo\ServerCheckAdmission::verify($connection, $journal);
        Assert::same($fixture->scalar("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tablo_migration_journal'", $connection), $role === 'writer' ? 0 : count($journal['checks']));
    }
    Assert::same(\Tablo\DatabaseSession::get($writer)?->metadataObservation, null, 'factory CHECK snapshots never escape admission');
}

/** @param array<string, string> $environment */
function engine_metadata_refuses(array $environment, string $category): void
{
    $caught = null;
    try { DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment(environment: $environment)); }
    catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, $category); Assert::same($caught?->getPrevious(), null);
}

function engine_metadata_control_record(EngineFixture $fixture, DatabaseConfiguration $configuration): array
{
    $path = \Tablo\Tests\Support\EngineSchedule::directory() . '/private/' . $fixture->case . '.metadata.json';
    if (is_link($path)) { throw new \RuntimeException('Linked owned metadata control refused.'); }
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($path, length: 4097));
    if (strlen($bytes) > 4096) { throw new \RuntimeException('Metadata control envelope exceeded.'); }
    $record = \Tablo\JsonRecord::decode($bytes);
    Assert::same([$record['schema'] ?? null, $record['nonce'] ?? null, $record['engine'] ?? null, $record['catalog'] ?? null],
        ['tablo/metadata-control/v1', getenv('TABLO_TEST_RUN_NONCE'), $configuration->engine, $configuration->catalog]);
    return $record;
}

function engine_metadata_snapshot(\PDO $db): array
{
    $rows = ['state' => ServerSchema::rows($db, 'SELECT id,format,logical_version,layout_checksum,phase,installation_id,committed_history_high_watermark FROM tablo_database_state'),
        'journal' => ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY step'), 'guard' => ServerSchema::rows($db, 'SELECT * FROM tablo_writer_guard')];
    foreach (\Tablo\DatabaseTransferManifest::tables() as $table) {
        $order = implode(',', array_map(static fn (string $column): string => '`' . $column . '`', $table['primary']));
        $rows[$table['name']] = ServerSchema::rows($db, 'SELECT * FROM `' . $table['name'] . '` ORDER BY ' . $order);
    }
    return $rows;
}

function engine_metadata_writer_grants(\PDO $db, DatabaseConfiguration $configuration): void
{
    $grantee = ServerMetadataIdentity::grantee($db, $configuration);
    Assert::same(ServerSchema::rows($db, 'SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ?', [$grantee]), []);
    $actual = ServerSchema::rows($db, 'SELECT TABLE_NAME AS name, PRIVILEGE_TYPE AS privilege_type, IS_GRANTABLE AS grantable FROM information_schema.TABLE_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? AND CAST(TABLE_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY) ORDER BY BINARY TABLE_NAME,BINARY PRIVILEGE_TYPE', [$grantee]);
    $expected = [];
    foreach (\Tablo\DatabaseTransferManifest::TABLES as $table) {
        foreach (['DELETE', 'INSERT', 'SELECT', 'UPDATE'] as $privilege) { $expected[] = ['name' => $table, 'privilege_type' => $privilege, 'grantable' => 'NO']; }
    }
    foreach (['tablo_database_state', 'tablo_migration_journal', 'tablo_writer_guard'] as $table) { $expected[] = ['name' => $table, 'privilege_type' => 'SELECT', 'grantable' => 'NO']; }
    $expected[] = ['name' => 'tablo_writer_guard', 'privilege_type' => 'UPDATE', 'grantable' => 'NO'];
    usort($expected, static fn (array $a, array $b): int => [$a['name'], $a['privilege_type']] <=> [$b['name'], $b['privilege_type']]);
    Assert::same($actual, $expected);
    Assert::same(ServerSchema::rows($db, 'SELECT TABLE_NAME AS name,COLUMN_NAME AS column_name,PRIVILEGE_TYPE AS privilege_type FROM information_schema.COLUMN_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? AND CAST(TABLE_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY)', [$grantee]),
        [['name' => 'tablo_database_state', 'column_name' => 'committed_history_high_watermark', 'privilege_type' => 'UPDATE']]);
}
