<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use PDOStatement;

/** Inject after a genuine native close, including execute/fetch failures and the EOF cursor. */
final class TransferRowsStatement extends PDOStatement
{
    private bool $data = false;
    private bool $closed = false;
    private bool $attempt = false;
    private bool $open = false;
    protected function __construct(private readonly TransferRowsPdo $owner)
    {
        $this->data = str_contains($this->queryString, ' ORDER BY ');
        if ($this->data) { $owner->counts['live']++; }
    }

    public function __destruct()
    {
        if ($this->data) { $this->owner->counts['live']--; }
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->beginAttempt();
        $this->fail('bind');
        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        $this->beginAttempt();
        if ($this->data) { $this->owner->counts['executed']++; }
        $this->fail('execute');
        $result = parent::execute($params);
        if ($this->data && $result) { $this->owner->counts['succeeded']++; $this->owner->counts['open']++; $this->open = true; }
        return $result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->data) { $this->owner->counts['fetched']++; }
        $this->fail('fetch');
        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function closeCursor(): bool
    {
        if (!$this->data) { return parent::closeCursor(); }
        if (!$this->attempt || $this->closed) { throw new \LogicException('Data cursor closed twice.'); }
        $this->owner->counts['closeAttempts']++;
        $result = parent::closeCursor();
        if (!$result) { return false; }
        $this->closed = true; $this->attempt = false; $this->owner->counts['closed']++;
        if ($this->open) { $this->owner->counts['open']--; $this->open = false; }
        $this->fail('close');
        return $result;
    }

    private function fail(string $operation): void
    {
        if ($this->data) { $this->owner->fail($operation); }
    }

    /** First binding starts a row attempt even when binding fails before execute(). */
    private function beginAttempt(): void
    {
        if (!$this->data) { return; }
        if ($this->open) { throw new \LogicException('Previous data cursor remains open.'); }
        if ($this->attempt) { return; }
        $this->attempt = true; $this->closed = false; $this->owner->counts['attempted']++;
    }
}
