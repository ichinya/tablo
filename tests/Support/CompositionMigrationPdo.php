<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use PDOStatement;

/** Native SQLite controls at sequence preparation and deferred COMMIT. */
final class CompositionMigrationPdo extends PDO
{
    public bool $refuseSequence = false;
    public bool $deferCommitFailure = false;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->exec('PRAGMA foreign_keys=ON');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->refuseSequence && str_starts_with($query, 'UPDATE main.sqlite_sequence')) {
            return parent::prepare('SELECT * FROM injected_sequence_failure', $options);
        }
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($this->deferCommitFailure && $statement === 'PRAGMA main.user_version = 9') {
            parent::exec('INSERT INTO migration_commit_control VALUES(-1)');
        }
        return $result;
    }
}
