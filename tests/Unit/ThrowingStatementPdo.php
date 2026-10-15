<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;

final class ThrowingStatementPdo extends PDO
{
    public function __construct(private readonly PDOException $failure) { parent::__construct('sqlite::memory:'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    { throw $this->failure; }
}
