<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use PDOStatement;

/** Any target SQL before complete archive admission is an observable regression. */
final class TransferTargetTripwirePdo extends PDO
{
    private bool $armed = false;
    public int $targetCalls = 0;

    public function __construct(string $path)
    { parent::__construct('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
    public function arm(): void { $this->targetCalls = 0; $this->armed = true; }
    public function disarm(): void { $this->armed = false; }
    public function exec(string $statement): int|false { $this->touch(); return parent::exec($statement); }
    public function prepare(string $query, array $options = []): PDOStatement|false
    { $this->touch(); return parent::prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->touch();
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    private function touch(): void
    {
        if ($this->armed) { ++$this->targetCalls; throw new \LogicException('Transfer target SQL preceded archive admission.'); }
    }
}
