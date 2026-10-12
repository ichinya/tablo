<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use PDOStatement;
use Tablo\Database;

/** Commits a genuine second-connection migration between autocommit metadata reads. */
final class SchemaObservationPdo extends PDO
{
    public int $observations = 0;

    public function __construct(private readonly string $path, private readonly ?\PDOException $failure = null)
    {
        parent::__construct('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query === 'PRAGMA main.table_info(sites)' && ++$this->observations === 1) {
            $other = new PDO('sqlite:' . $this->path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            Database::migrate($other);
            unset($other);
            if ($this->failure !== null) { throw $this->failure; }
        }
        return parent::query($query);
    }
}
