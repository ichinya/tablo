<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use PDOStatement;

/** Real SQLite rows with a selected typed storage failure at the repository boundary. */
final class FailureBoundaryPdo extends PDO
{
    private ?\Throwable $failure = null;
    private string $fragment = '';

    public function __construct(string $path) { parent::__construct('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }

    public function arm(\Throwable $failure, string $fragment): void
    { $this->failure = $failure; $this->fragment = $fragment; }

    public function disarm(): void { $this->failure = null; }

    public function prepare(string $query, array $options = []): PDOStatement|false
    { $this->refuse($query); return parent::prepare($query, $options); }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->refuse($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function refuse(string $query): void
    {
        if ($this->failure !== null && str_contains($query, $this->fragment)) { throw $this->failure; }
    }
}
