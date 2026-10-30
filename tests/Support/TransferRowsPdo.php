<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use PDOStatement;

/** Real SQLite rows; only ordered data cursor lifecycle and selected faults are observed. */
final class TransferRowsPdo extends PDO
{
    public int $prepared = 0;
    public int $closed = 0;
    public string $fault = '';

    public function __construct()
    {
        parent::__construct('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY,open_issues INTEGER,endpoint_token TEXT,last_error TEXT,checked_at TEXT NOT NULL)');
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TransferRowsStatement::class, [$this]]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, ' ORDER BY ')) { $this->prepared++; }
        return parent::prepare($query, $options);
    }
}
