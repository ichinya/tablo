<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Closure;
use PDO;
use PDOStatement;
use RuntimeException;

// Real SQLite with SQL observation, barriers and deliberate SQL failures for migration tests.
final class MigrationPdo extends PDO
{
    public array $statements = [];
    public ?Closure $beforeBegin = null;
    public ?Closure $afterBegin = null;
    public bool $failAfterFirstCreate = false;
    public ?string $failBefore = null;
    public ?string $failAfter = null;
    public bool $failRollback = false;

    public function __construct(string $path)
    {
        parent::__construct('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        $this->statements = [];
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        if ($statement === 'BEGIN IMMEDIATE' && $this->beforeBegin !== null) { ($this->beforeBegin)(); }
        if ($statement === 'ROLLBACK' && $this->failRollback) { throw new RuntimeException('Injected rollback failure'); }
        if ($this->failBefore !== null && str_starts_with($statement, $this->failBefore)) {
            return parent::exec('SELECT * FROM injected_migration_failure');
        }
        if ($this->failAfterFirstCreate && str_starts_with($statement, 'CREATE TABLE')) {
            parent::exec(explode(';', $statement, 2)[0] . ';');
            return parent::exec('SELECT * FROM injected_migration_failure');
        }
        $result = parent::exec($statement);
        if ($statement === 'BEGIN IMMEDIATE' && $this->afterBegin !== null) { ($this->afterBegin)(); }
        if ($this->failAfter !== null && str_starts_with($statement, $this->failAfter)) {
            return parent::exec('SELECT * FROM injected_migration_failure');
        }
        return $result;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements[] = $query;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
