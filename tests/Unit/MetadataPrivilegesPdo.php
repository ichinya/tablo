<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use PDOStatement;
use RuntimeException;

/** Closed row model; deliberately never opens a native PDO connection. */
final class MetadataPrivilegesPdo extends PDO
{
    public int $opened = 0;
    public int $closed = 0;
    /** @var list<string> */
    public array $queries = [];

    /** @param list<?string> $enabled
     * @param list<?string> $applicable */
    public function __construct(public readonly string $engine, public readonly array $enabled = [], public readonly array $applicable = [], public readonly string $fault = '') {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($options !== []) { throw new RuntimeException('Unexpected metadata prepare options.'); }
        $this->queries[] = $query; $this->opened++;
        return new MetadataPrivilegesStatement($this, $query);
    }

    /** @return list<array> */
    public function rows(string $query): array
    {
        if (str_starts_with($this->fault, 'checks/') && str_contains($query, 'FROM information_schema.CHECK_CONSTRAINTS')) { return metadata_checks_rows($this, $query); }
        return match ($query) {
            'SELECT CURRENT_USER() AS identity' => [['identity' => $this->fault === 'identity' ? 'foreign@%' : 'meta@%']],
            'SELECT 1 AS unsupported FROM information_schema.APPLICABLE_ROLES LIMIT 1' => $this->applicable === [] ? [] : [['unsupported' => 1]],
            'SELECT 1 AS unsupported FROM information_schema.ENABLED_ROLES LIMIT 1' => $this->enabled === [] ? [] : [['unsupported' => 1]],
            'SELECT 1 AS unsupported FROM information_schema.ENABLED_ROLES WHERE ROLE_NAME IS NOT NULL LIMIT 1' => $this->filteredRoles(),
            'SELECT @@GLOBAL.partial_revokes AS partial_revokes' => [['partial_revokes' => (int) ($this->fault === 'partial-revokes')]],
            'SELECT PRIVILEGE_TYPE AS privilege_type, IS_GRANTABLE AS grantable FROM information_schema.USER_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? ORDER BY PRIVILEGE_TYPE LIMIT 2'
                => [['privilege_type' => $this->fault === 'global' ? 'SUPER' : 'USAGE', 'grantable' => 'NO']],
            'SELECT TABLE_SCHEMA AS catalog, PRIVILEGE_TYPE AS privilege_type, IS_GRANTABLE AS grantable FROM information_schema.SCHEMA_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? ORDER BY TABLE_SCHEMA, PRIVILEGE_TYPE LIMIT 5'
                => $this->schemaGrants(),
            'SELECT 1 AS extra FROM information_schema.TABLE_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? LIMIT 1' => $this->fault === 'table' ? [['extra' => 1]] : [],
            'SELECT 1 AS extra FROM information_schema.COLUMN_PRIVILEGES WHERE CAST(GRANTEE AS BINARY) = ? LIMIT 1' => $this->fault === 'column' ? [['extra' => 1]] : [],
            default => throw new RuntimeException('Unexpected metadata SQL.'),
        };
    }

    /** @return list<array{unsupported: int}> */
    private function filteredRoles(): array
    {
        return metadata_privileges_filtered_roles($this);
    }

    /** @return list<array{catalog: string, privilege_type: string, grantable: string}> */
    private function schemaGrants(): array
    {
        $names = ['EVENT', 'SELECT', 'TRIGGER', $this->engine === 'mysql' ? 'ALTER ROUTINE' : 'SHOW CREATE ROUTINE']; sort($names, SORT_STRING);
        $rows = array_map(static fn (string $name): array => ['catalog' => 'tablo\\_meta', 'privilege_type' => $name, 'grantable' => 'NO'], $names);
        if ($this->fault === 'missing') { array_pop($rows); }
        if ($this->fault === 'extra') { $rows[] = ['catalog' => 'tablo\\_meta', 'privilege_type' => 'UPDATE', 'grantable' => 'NO']; }
        if ($this->fault === 'grant-option') { $rows[0]['grantable'] = 'YES'; }
        if ($this->fault === 'catalog') { $rows[0]['catalog'] = 'foreign'; }
        return $rows;
    }
}

/** @return list<array{unsupported: int}> */
function metadata_privileges_filtered_roles(MetadataPrivilegesPdo $db): array
{
    if ($db->engine !== 'mariadb') { throw new RuntimeException('MySQL role query changed.'); }
    return array_filter($db->enabled, static fn (?string $role): bool => $role !== null) === [] ? [] : [['unsupported' => 1]];
}
