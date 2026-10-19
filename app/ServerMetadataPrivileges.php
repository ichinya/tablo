<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** A finite direct-grant profile; filtered counts alone never prove visibility. */
final class ServerMetadataPrivileges
{
    public static function verify(PDO $db, #[\SensitiveParameter] DatabaseConfiguration $configuration): void
    {
        $grantee = ServerMetadataIdentity::grantee($db, $configuration);
        self::roles($db, $configuration);
        $global = ServerSchema::rows($db, 'SELECT PRIVILEGE_TYPE AS privilege_type, IS_GRANTABLE AS grantable FROM information_schema.USER_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? ORDER BY PRIVILEGE_TYPE LIMIT 2', [$grantee]);
        if ($global !== [] && $global !== [['privilege_type' => 'USAGE', 'grantable' => 'NO']]) { throw new DatabaseFailure('schema'); }
        $schema = ServerSchema::rows($db, 'SELECT TABLE_SCHEMA AS catalog, PRIVILEGE_TYPE AS privilege_type, IS_GRANTABLE AS grantable FROM information_schema.SCHEMA_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? ORDER BY TABLE_SCHEMA, PRIVILEGE_TYPE LIMIT 5', [$grantee]);
        $privileges = ['EVENT', 'SELECT', 'TRIGGER', $configuration->engine === 'mysql' ? 'ALTER ROUTINE' : 'SHOW CREATE ROUTINE'];
        sort($privileges, SORT_STRING);
        $catalog = str_replace('_', replace: '\\_', subject: $configuration->catalog);
        $expected = array_map(static fn (string $privilege): array => ['catalog' => $catalog, 'privilege_type' => $privilege, 'grantable' => 'NO'], $privileges);
        if ($schema !== $expected) { throw new DatabaseFailure('schema'); }
        foreach (['TABLE_PRIVILEGES', 'COLUMN_PRIVILEGES'] as $table) {
            if (ServerSchema::rows($db, 'SELECT 1 AS extra FROM information_schema.' . $table . ' WHERE CAST(GRANTEE AS BINARY) = ? LIMIT 1', [$grantee]) !== []) { throw new DatabaseFailure('schema'); }
        }
    }

    private static function roles(PDO $db, #[\SensitiveParameter] DatabaseConfiguration $configuration): void
    {
        foreach (['APPLICABLE_ROLES', 'ENABLED_ROLES'] as $table) {
            if (ServerSchema::rows($db, 'SELECT 1 AS unsupported FROM information_schema.' . $table . ' LIMIT 1') !== []) { throw new DatabaseFailure('schema'); }
        }
        if ($configuration->engine === 'mysql') {
            $row = ServerSchema::rows($db, 'SELECT @@GLOBAL.partial_revokes AS partial_revokes')[0] ?? throw new DatabaseFailure('schema');
            if (DatabaseInteger::checked($row['partial_revokes'] ?? null) !== 0) { throw new DatabaseFailure('schema'); }
        }
    }
}
