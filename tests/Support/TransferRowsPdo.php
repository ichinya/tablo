<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use PDOStatement;

/** Real SQLite rows; only ordered data cursor lifecycle and selected faults are observed. */
final class TransferRowsPdo extends PDO
{
    /** @var array{prepareAttempts: int, prepared: int, attempted: int, executed: int, succeeded: int,
     * fetched: int, closeAttempts: int, closed: int, open: int, live: int} */
    public array $counts = ['prepareAttempts' => 0, 'prepared' => 0, 'attempted' => 0, 'executed' => 0, 'succeeded' => 0,
        'fetched' => 0, 'closeAttempts' => 0, 'closed' => 0, 'open' => 0, 'live' => 0];
    public string $fault = '';
    public int $faultAt = 2;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY,open_issues INTEGER,endpoint_token TEXT,last_error TEXT,checked_at TEXT NOT NULL)');
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TransferRowsStatement::class, [$this]]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $data = str_contains($query, ' ORDER BY ');
        if ($data) {
            $this->counts['prepareAttempts']++; $this->fail('prepare');
        }
        $statement = parent::prepare($query, $options);
        if ($data && $statement !== false) { $this->counts['prepared']++; }
        return $statement;
    }

    public function fail(string $operation): void
    {
        $ordinal = $operation === 'prepare' ? $this->counts['prepareAttempts'] : $this->counts['attempted'];
        if ($this->fault === $operation && $ordinal === $this->faultAt) {
            throw new \PDOException('Synthetic transfer ' . $operation . ' failure.');
        }
    }
}
