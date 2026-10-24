<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\DatabaseConfiguration;
use Tablo\DatabaseFailure;
use Tablo\ServerMetadataPrivileges;
use Testo\Assert;
use Testo\Test;

final class ServerMetadataPrivilegesTest
{
    #[Test]
    public function mariaDbNoActiveRoleSentinelReachesTheCompleteDirectGrantChecks(): void
    { metadata_privileges_sentinel(); metadata_checks_visible_to_select_only_writer(); }

    #[Test]
    public function actualEnabledRolesNeverHideBehindNullOrBecomePublicExceptions(): void
    { metadata_privileges_enabled_roles(); }

    #[Test]
    public function inactiveApplicableRolesAndPublicRemainForbidden(): void
    { metadata_privileges_applicable(); }

    #[Test]
    public function acceptingTheSentinelCannotSkipIdentityOrGrantValidation(): void
    { metadata_privileges_grants(); }

    #[Test]
    public function mysqlRetainsItsExistingQueriesAndPartialRevokePolicy(): void
    { metadata_privileges_mysql(); }
}

function metadata_checks_visible_to_select_only_writer(): void
{
    foreach (['mariadb', 'mysql'] as $engine) {
        foreach (['writer', 'control', 'migrator', 'reader'] as $role) {
            foreach (['', 'missing', 'extra', 'duplicate', 'wrong-name', 'changed', 'unenforced'] as $fault) {
                metadata_checks_role_case($engine, $role, $fault);
            }
        }
    }
    foreach (['writer', 'control'] as $role) { metadata_checks_bound_snapshot($role); }
}

function metadata_checks_role_case(string $engine, string $role, string $fault): void
{
    $db = new MetadataPrivilegesPdo($engine, fault: 'checks/' . $fault);
    $configuration = metadata_checks_configuration($engine, $role);
    $session = \Tablo\DatabaseSession::register($db, $configuration);
    $privileged = $engine === 'mariadb' && in_array($role, ['writer', 'control'], strict: true);
    $caught = null; $metadata = null;
    try {
        if ($privileged) { $metadata = metadata_checks_observation($db, $configuration); }
        \Tablo\ServerCheckAdmission::verify($db, metadata_checks_table($engine));
    } catch (DatabaseFailure $error) { $caught = $error; }
    finally { $session->metadataObservation = null; }
    Assert::same($db->opened, $db->closed);
    if ($metadata !== null) { Assert::same($metadata->opened, $metadata->closed); }
    $expected = $fault === '' || ($engine === 'mariadb' && $fault === 'unenforced') ? null : 'schema';
    Assert::same($caught?->category, $expected);
    Assert::same($caught?->getPrevious(), null);
    Assert::same(count(array_filter($db->queries, static fn (string $query): bool => str_contains($query, 'FROM information_schema.CHECK_CONSTRAINTS'))), $privileged ? 0 : 1);
    if (!$privileged && $fault === '') { metadata_checks_direct_role($db, $configuration); }
}

function metadata_checks_configuration(string $engine, string $role): DatabaseConfiguration
{
    return DatabaseConfiguration::fromEnvironment(role: $role, environment: ['TABLO_DB_DRIVER' => $engine, 'TABLO_DB_NAME' => 'tablo_meta',
        'TABLO_DB_HOST' => '127.0.0.1', 'TABLO_DB_PORT' => '3306', 'TABLO_DB_USER' => 'writer', 'TABLO_DB_PASSWORD' => '', 'TABLO_DB_TLS' => 'local',
        'TABLO_DB_MIGRATE_USER' => 'migrator', 'TABLO_DB_MIGRATE_PASSWORD' => '', 'TABLO_DB_READ_USER' => 'reader', 'TABLO_DB_READ_PASSWORD' => '']);
}

function metadata_checks_direct_role(MetadataPrivilegesPdo $db, DatabaseConfiguration $configuration): void
{
    $queries = $db->queries;
    Assert::true(class_exists(\Tablo\ServerMetadataAdmission::class));
    Assert::same(\Tablo\server_metadata_check_observation($db, $configuration, version: 10), []);
    Assert::same(\Tablo\server_metadata_check_observation($db, $configuration, version: 10, expectedTables: \Tablo\ServerSchema::layout($db)['tables']), []);
    $caught = null;
    try { \Tablo\ServerMetadataAdmission::checks($db, 'tablo_migration_journal'); } catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    Assert::same($db->queries, $queries); Assert::same($db->opened, $db->closed);
}

/** @return \Tablo\ServerSchema::Table */
function metadata_checks_table(string $engine, string $name = 'tablo_migration_journal'): array
{
    /** @var \Tablo\ServerSchema::Layout $layout Public descriptor fixture. */
    $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/database/' . ($engine === 'mysql' ? 'mysql84' : 'mariadb114') . '-layout.json')),
        associative: true, flags: JSON_THROW_ON_ERROR);
    foreach ($layout['tables'] as $table) { if ($table['name'] === $name) { return $table; } }
    throw new \RuntimeException('Missing public table descriptor.');
}

/** Models table-only SELECT filtering through both MariaDB metadata queries.
 * @return list<array> */
function metadata_checks_rows(MetadataPrivilegesPdo $db, string $query, string $name): array
{
    if ($db->engine === 'mariadb' && in_array(\Tablo\DatabaseSession::get($db)?->configuration->role, ['writer', 'control'], strict: true)) { return []; }
    if ($db->engine === 'mysql' && !str_contains($query, 't.ENFORCED AS enforced')) { throw new \RuntimeException('MySQL enforcement query lost.'); }
    $table = metadata_checks_table($db->engine, $name); $rows = [];
    foreach ($table['checks'] as $check) {
        $clause = $db->engine === 'mysql' ? strtr($check['expression'], ["'" => "\\'"]) : $check['expression'];
        $rows[] = ['name' => $check['name'], 'clause' => $clause, 'enforced' => str_ends_with($db->fault, '/unenforced') ? 'NO' : 'YES'];
    }
    if ($db->fault === 'checks/missing') { array_pop($rows); }
    if ($db->fault === 'checks/extra') { $rows[] = ['name' => 'unexpected', 'clause' => 'id = 1', 'enforced' => 'YES']; }
    if ($db->fault === 'checks/duplicate') { $rows[0] = $rows[1] ?? throw new \RuntimeException('Missing public CHECK.'); }
    if ($db->fault === 'checks/wrong-name') { $rows[0]['name'] = 'unexpected'; }
    if ($db->fault === 'checks/changed') { $rows[0]['clause'] = 'step >= 1'; }
    return $rows;
}

/** @param array<int|string, mixed> $bindings
 * @return list<array> */
function metadata_checks_query(MetadataPrivilegesPdo $db, string $query, array $bindings): array
{
    $expected = $db->engine === 'mysql'
        ? 'SELECT c.CONSTRAINT_NAME AS name, c.CHECK_CLAUSE AS clause, t.ENFORCED AS enforced FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = ?'
        : 'SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    if ($query === $expected) { return metadata_checks_rows($db, $query, \Tablo\SqlColumn::string($bindings, key: '1')); }
    if ($query === 'SELECT DATABASE() AS catalog, VERSION() AS version, @@version_comment AS comment, @@hostname AS host, @@port AS port, @@server_id AS server_id, @@server_uid AS server_uid') {
        return [['catalog' => 'tablo_meta', 'version' => '11.4', 'comment' => 'fixture', 'host' => 'fixture', 'port' => 3306, 'server_id' => 1, 'server_uid' => 'fixture']];
    }
    throw new \RuntimeException('Unexpected CHECK metadata SQL.');
}

function metadata_checks_observation(MetadataPrivilegesPdo $writer, DatabaseConfiguration $configuration): MetadataPrivilegesPdo
{
    $metadata = new MetadataPrivilegesPdo('mariadb', fault: $writer->fault);
    $environment = ['TABLO_DB_DRIVER' => 'mariadb', 'TABLO_DB_NAME' => 'tablo_meta', 'TABLO_DB_HOST' => '127.0.0.1', 'TABLO_DB_PORT' => '3306',
        'TABLO_DB_USER' => 'writer', 'TABLO_DB_PASSWORD' => '', 'TABLO_DB_TLS' => 'local', 'TABLO_DB_METADATA_USER' => 'meta', 'TABLO_DB_METADATA_PASSWORD' => ''];
    \Tablo\DatabaseSession::register($metadata, DatabaseConfiguration::fromEnvironment(environment: $environment)->metadata());
    Assert::true(class_exists(\Tablo\ServerMetadataAdmission::class));
    try { $checks = metadata_checks_invocation_tables($metadata, $configuration); }
    finally { Assert::same($metadata->opened, $metadata->closed); }
    $session = \Tablo\DatabaseSession::get($writer) ?? throw new \RuntimeException('Missing writer session.');
    $session->metadataObservation = ['server' => \Tablo\ServerMetadataIdentity::server($writer), 'account' => "'meta'@'%'",
        'objects' => [['name' => 'tablo_migration_journal', 'kind' => 'BASE TABLE']], 'installation' => [], 'checks' => $checks];
    return $metadata;
}

/** Both paths use the existing closed metadata model, never a connection factory seam.
 * @return array<string, array<string, string>> */
function metadata_checks_invocation_tables(MetadataPrivilegesPdo $metadata, DatabaseConfiguration $configuration): array
{
    $tables = \Tablo\ServerSchema::layout($metadata)['tables']; $before = $tables;
    $default = []; $provided = []; $defaultFailure = null; $providedFailure = null;
    try { $default = \Tablo\server_metadata_check_observation($metadata, $configuration, version: 10); }
    catch (DatabaseFailure $error) { $defaultFailure = $error; }
    try { $provided = \Tablo\server_metadata_check_observation($metadata, $configuration, version: 10, expectedTables: $tables); }
    catch (DatabaseFailure $error) { $providedFailure = $error; }
    Assert::same($providedFailure?->category, $defaultFailure?->category);
    Assert::same($providedFailure?->getPrevious(), null); Assert::same($tables, $before);
    if ($defaultFailure !== null) { throw $defaultFailure; }
    Assert::same($provided, $default);
    Assert::same(array_keys($provided), array_column($tables, 'name'));
    return $default;
}

function metadata_checks_bound_snapshot(string $role): void
{
    $db = new MetadataPrivilegesPdo('mariadb', fault: 'checks/');
    $configuration = metadata_checks_configuration('mariadb', $role);
    $session = \Tablo\DatabaseSession::register($db, $configuration);
    // Both raw views are filtered for table-level SELECT; only the bound schema-level observation works.
    $table = metadata_checks_table('mariadb');
    $caught = null;
    try { \Tablo\ServerCheckAdmission::clauses($db, $table); } catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema');
    metadata_checks_observation($db, $configuration);
    $original = $session->metadataObservation ?? throw new \RuntimeException('Missing CHECK snapshot.');
    foreach (['count', 'server', 'missing'] as $fault) {
        $session->metadataObservation = $original; $candidate = $table;
        if ($fault === 'count') { $candidate['checks'] = []; }
        if ($fault === 'server') { $session->metadataObservation['server']['catalog'] = 'foreign'; }
        if ($fault === 'missing') { unset($session->metadataObservation['checks'][$table['name']]); }
        $borrowed = $session->metadataObservation;
        $caught = null;
        try { \Tablo\ServerCheckAdmission::verify($db, $candidate); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::same($session->metadataObservation, $borrowed);
    }
    metadata_layout_invocation_refusals($db, $original);
    metadata_layout_bound_snapshot($db, $original);
    $session->metadataObservation = null;
    $caught = null;
    try { \Tablo\ServerCheckAdmission::verify($db, $table); } catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'configuration', 'cleared snapshots must request a fresh admitted observation');
    Assert::same($db->opened, $db->closed);
}

/** A partial bound inventory must refuse with either descriptor path; no fake SQL surface is expanded.
 * @param \Tablo\ServerMetadataAdmission::Observation $observation */
function metadata_layout_invocation_refusals(MetadataPrivilegesPdo $db, array $observation): void
{
    $session = \Tablo\DatabaseSession::get($db) ?? throw new \RuntimeException('Missing admitted session.');
    $tables = \Tablo\ServerSchema::layout($db)['tables']; $session->metadataObservation = $observation;
    foreach ([0, 9, 10, 11] as $version) {
        foreach ([null, $tables] as $provided) {
            $caught = null;
            try { \Tablo\ServerMetadataAdmission::objects($db, $version, expectedTables: $provided); }
            catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
            Assert::same($session->metadataObservation, $observation);
        }
    }
}

/** @param \Tablo\ServerMetadataAdmission::Observation $observation */
function metadata_layout_bound_snapshot(MetadataPrivilegesPdo $db, array $observation): void
{
    $session = \Tablo\DatabaseSession::get($db) ?? throw new \RuntimeException('Missing admitted session.');
    try {
        foreach (['server', 'objects'] as $fault) {
            $borrowed = $observation;
            if ($fault === 'server') { $borrowed['server']['catalog'] = 'foreign'; }
            if ($fault === 'objects') { $borrowed['objects'] = []; }
            $session->metadataObservation = $borrowed; $caught = null;
            try { \Tablo\ServerSchema::validateLayout($db); } catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
            Assert::same($session->metadataObservation, $borrowed);
        }
    } finally { $session->metadataObservation = null; }
    $queries = $db->queries; $caught = null;
    try { \Tablo\ServerSchema::validateLayout($db); } catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'configuration'); Assert::same($caught?->getPrevious(), null);
    Assert::same($session->metadataObservation, null); Assert::same($db->queries, $queries);
    Assert::same($db->opened, $db->closed);
}

function metadata_privileges_sentinel(): void
{
    foreach ([[], [null], [null, null]] as $roles) {
        $db = new MetadataPrivilegesPdo('mariadb', $roles); metadata_privileges_check($db);
        Assert::same(count($db->queries), 7);
    }
}

function metadata_privileges_enabled_roles(): void
{
    foreach (['staff', 'PUBLIC', 'NULL', ''] as $role) {
        foreach ([[$role], [null, $role], [$role, null]] as $roles) { metadata_privileges_check(new MetadataPrivilegesPdo('mariadb', $roles), expected: 'schema'); }
    }
}

function metadata_privileges_applicable(): void
{
    foreach (['mysql', 'mariadb'] as $engine) {
        foreach (['staff', 'PUBLIC', 'NULL', null] as $role) {
            metadata_privileges_check(new MetadataPrivilegesPdo($engine, $engine === 'mysql' ? [] : [null], [$role]), expected: 'schema');
        }
    }
}

function metadata_privileges_grants(): void
{
    foreach (['identity', 'global', 'missing', 'extra', 'grant-option', 'catalog', 'table', 'column'] as $fault) {
        metadata_privileges_check(new MetadataPrivilegesPdo('mariadb', [null], fault: $fault), expected: 'schema');
    }
}

function metadata_privileges_mysql(): void
{
    $db = new MetadataPrivilegesPdo('mysql'); metadata_privileges_check($db); Assert::same(count($db->queries), 8);
    Assert::true(in_array('SELECT 1 AS unsupported FROM information_schema.ENABLED_ROLES LIMIT 1', $db->queries, strict: true));
    foreach ([[null], ['staff'], ['PUBLIC']] as $roles) { metadata_privileges_check(new MetadataPrivilegesPdo('mysql', $roles), expected: 'schema'); }
    metadata_privileges_check(new MetadataPrivilegesPdo('mysql', fault: 'partial-revokes'), expected: 'schema');
}

function metadata_privileges_check(MetadataPrivilegesPdo $db, ?string $expected = null): void
{
    $configuration = DatabaseConfiguration::fromEnvironment(environment: ['TABLO_DB_DRIVER' => $db->engine, 'TABLO_DB_NAME' => 'tablo_meta',
        'TABLO_DB_HOST' => '127.0.0.1', 'TABLO_DB_PORT' => '3306', 'TABLO_DB_USER' => 'meta', 'TABLO_DB_PASSWORD' => '', 'TABLO_DB_TLS' => 'local']);
    $failure = null;
    try { ServerMetadataPrivileges::verify($db, $configuration); } catch (DatabaseFailure $error) { $failure = $error->category; }
    finally { Assert::same($db->opened, $db->closed); }
    Assert::same($failure, $expected);
}
