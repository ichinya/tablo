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
        foreach (['', 'missing', 'extra', 'duplicate', 'wrong-name', 'changed', 'unenforced'] as $fault) {
            $db = new MetadataPrivilegesPdo($engine, fault: 'checks/' . $fault);
            $configuration = DatabaseConfiguration::fromEnvironment(environment: ['TABLO_DB_DRIVER' => $engine, 'TABLO_DB_NAME' => 'tablo_meta',
                'TABLO_DB_HOST' => '127.0.0.1', 'TABLO_DB_PORT' => '3306', 'TABLO_DB_USER' => 'writer', 'TABLO_DB_PASSWORD' => '', 'TABLO_DB_TLS' => 'local']);
            \Tablo\DatabaseSession::register($db, $configuration);
            $caught = null;
            try { \Tablo\ServerCheckAdmission::verify($db, metadata_checks_table($engine)); } catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($db->opened, $db->closed);
            $expected = $fault === '' || ($engine === 'mariadb' && $fault === 'unenforced') ? null : 'schema';
            Assert::same($caught?->category, $expected);
            Assert::same($caught?->getPrevious(), null);
            Assert::same(count($db->queries), 1);
        }
    }
}

/** @return \Tablo\ServerSchema::Table */
function metadata_checks_table(string $engine): array
{
    /** @var \Tablo\ServerSchema::Layout $layout Public descriptor fixture. */
    $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/database/' . ($engine === 'mysql' ? 'mysql84' : 'mariadb114') . '-layout.json')),
        associative: true, flags: JSON_THROW_ON_ERROR);
    return $layout['tables'][0] ?? throw new \RuntimeException('Missing public journal descriptor.');
}

/** Models MariaDB's non-SELECT TABLE_CONSTRAINTS visibility without granting any extra privilege.
 * @return list<array> */
function metadata_checks_rows(MetadataPrivilegesPdo $db, string $query): array
{
    if ($db->engine === 'mariadb' && str_contains($query, 'TABLE_CONSTRAINTS')) { return []; }
    if ($db->engine === 'mysql' && !str_contains($query, 't.ENFORCED AS enforced')) { throw new \RuntimeException('MySQL enforcement query lost.'); }
    $table = metadata_checks_table($db->engine); $rows = [];
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
