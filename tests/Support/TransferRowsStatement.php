<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use PDOStatement;

/** Inject after a genuine native close, including execute/fetch failures and the EOF cursor. */
final class TransferRowsStatement extends PDOStatement
{
    private bool $closed = false;
    protected function __construct(private readonly TransferRowsPdo $owner) {}

    public function execute(?array $params = null): bool
    {
        $this->fail('execute');
        return parent::execute($params);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $this->fail('fetch');
        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function closeCursor(): bool
    {
        if (!str_contains($this->queryString, ' ORDER BY ')) { return parent::closeCursor(); }
        if ($this->closed) { throw new \LogicException('Data cursor closed twice.'); }
        $result = parent::closeCursor(); $this->closed = true; $this->owner->closed++;
        $this->fail('close');
        return $result;
    }

    private function fail(string $operation): void
    {
        if (str_contains($this->queryString, ' ORDER BY ') && $this->owner->prepared === 2 && $this->owner->fault === $operation) {
            throw new \PDOException('Synthetic transfer ' . $operation . ' failure.');
        }
    }
}
