<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use PDOStatement;
use RuntimeException;

/** Only the statement operations used by ServerSchema::rows are available. */
final class MetadataPrivilegesStatement extends PDOStatement
{
    /** @var array<int|string, mixed> */
    private array $bindings = [];
    private bool $executed = false;
    private bool $fetched = false;
    private bool $closed = false;

    public function __construct(private readonly MetadataPrivilegesPdo $owner, private readonly string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $expected = metadata_statement_bindings($this->sql, $value);
        if ($this->executed || $this->closed || $type !== PDO::PARAM_STR || $param !== count($this->bindings) + 1
            || !array_key_exists($param, $expected) || $value !== $expected[$param]) {
            throw new RuntimeException('Unexpected metadata binding.');
        }
        $this->bindings[$param] = $value; return true;
    }
    public function execute(?array $params = null): bool
    {
        $expected = metadata_statement_bindings($this->sql, $this->bindings[1] ?? null);
        if ([$params, $this->executed, $this->closed, $this->bindings] !== [null, false, false, $expected]) { throw new RuntimeException('Unexpected metadata execution.'); }
        $this->executed = true; return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ([$mode, $args, $this->executed, $this->fetched, $this->closed] !== [PDO::FETCH_ASSOC, [], true, false, false]) { throw new RuntimeException('Unexpected metadata fetch.'); }
        $this->fetched = true; return $this->owner->rows($this->sql, $this->bindings);
    }
    public function closeCursor(): bool
    {
        if ($this->closed) { throw new RuntimeException('Metadata cursor closed twice.'); }
        $this->closed = true; $this->owner->closed++; return true;
    }
}

/** @return array<int, string> */
function metadata_statement_bindings(string $query, mixed $candidate = null): array
{
    if (str_contains($query, 'FROM information_schema.CHECK_CONSTRAINTS')) {
        $table = is_string($candidate) && $candidate !== 'CHECK' ? $candidate : 'tablo_migration_journal';
        metadata_checks_table('mariadb', $table);
        return str_contains($query, 'TABLE_CONSTRAINTS') ? [1 => $table, 2 => 'CHECK'] : [1 => $table];
    }
    return str_contains($query, 'CAST(GRANTEE AS BINARY) = ?') ? [1 => "'meta'@'%'"] : [];
}
