<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Session-affine lifetime mutex. DML commits do not release this lock. */
final class ServerLock
{
    private ?ServerLockOwnership $ownership = null;
    private string $name = '';

    public function acquire(PDO $db, string $domain, int $timeout = 0): bool
    {
        if ($this->ownership !== null || !in_array($domain, ['migration', 'worker', 'channel'], strict: true)
            || !in_array($timeout, [0, 5], strict: true) || WriteTransaction::owns($db) || $db->inTransaction()) { throw new \LogicException('Invalid database lock order.'); }
        $session = DatabaseSession::get($db);
        if ($session === null) { throw new DatabaseFailure('configuration'); }
        // Catalog on this authenticated server, rather than configured host aliases.
        $this->name = 'tablo:' . $domain . ':' . substr(hash('sha256', $session->configuration->catalog), offset: 0, length: 44);
        try {
            $statement = SqlStatement::checked($db->prepare('SELECT GET_LOCK(?, ?)'));
            try {
                $statement->execute([$this->name, $timeout]);
                /** @var int|string|null|false $result Native GET_LOCK scalar result. */
                $result = $statement->fetchColumn();
            } finally { $statement->closeCursor(); }
            if ($result === null || $result === false) { throw new DatabaseFailure('busy'); }
            if (DatabaseInteger::checked($result) === 0) { return false; }
            if (DatabaseInteger::checked($result) !== 1) { throw new DatabaseFailure('busy'); }
            $statement = SqlStatement::checked($db->query('SELECT CONNECTION_ID()'));
            try { $connectionId = DatabaseInteger::checked($statement->fetchColumn()); } finally { $statement->closeCursor(); }
            $this->ownership = new ServerLockOwnership($db, $connectionId, $this->name);
            $this->assertOwned();
            return true;
        } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
    }

    public function assertOwned(): void
    {
        if ($this->ownership === null) { throw new DatabaseFailure('busy'); }
        try { $this->ownership->assertOwned(); } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
    }

    public function close(): void
    {
        try { $this->ownership?->release(); } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
        finally { $this->ownership = null; }
    }
}
