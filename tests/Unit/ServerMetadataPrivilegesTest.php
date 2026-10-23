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
    { metadata_privileges_sentinel(); }

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
