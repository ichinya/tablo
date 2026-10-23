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
        if ([$this->executed, $this->closed, $param, $value, $type, $this->bindings] !== [false, false, 1, "'meta'@'%'", PDO::PARAM_STR, []]) {
            throw new RuntimeException('Unexpected metadata binding.');
        }
        $this->bindings[$param] = $value; return true;
    }
    public function execute(?array $params = null): bool
    {
        $expected = str_contains($this->sql, 'CAST(GRANTEE AS BINARY) = ?') ? [1 => "'meta'@'%'"] : [];
        if ([$params, $this->executed, $this->closed, $this->bindings] !== [null, false, false, $expected]) { throw new RuntimeException('Unexpected metadata execution.'); }
        $this->executed = true; return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ([$mode, $args, $this->executed, $this->fetched, $this->closed] !== [PDO::FETCH_ASSOC, [], true, false, false]) { throw new RuntimeException('Unexpected metadata fetch.'); }
        $this->fetched = true; return $this->owner->rows($this->sql);
    }
    public function closeCursor(): bool
    {
        if ($this->closed) { throw new RuntimeException('Metadata cursor closed twice.'); }
        $this->closed = true; $this->owner->closed++; return true;
    }
}
