<?php
declare(strict_types=1);

namespace Tablo;

final class ServerLockOwnership
{
    public function __construct(private readonly \PDO $db, private readonly int $connectionId, private readonly string $name) {}

    public function assertOwned(): void
    {
        $statement = SqlStatement::checked($this->db->prepare('SELECT IS_USED_LOCK(?)'));
        try {
            $statement->execute([$this->name]);
            /** @var int|string|null|false $owner Native lock owner or SQL NULL; false remains no row. */
            $owner = $statement->fetchColumn();
        } finally { $statement->closeCursor(); }
        if ($owner === null || $owner === false || DatabaseInteger::checked($owner) !== $this->connectionId) { throw new DatabaseFailure('busy'); }
    }

    public function release(): void
    {
        $this->assertOwned();
        $statement = SqlStatement::checked($this->db->prepare('SELECT RELEASE_LOCK(?)'));
        try {
            $statement->execute([$this->name]);
            /** @var int|string|null|false $released Native release result or SQL NULL; false remains no row. */
            $released = $statement->fetchColumn();
        } finally { $statement->closeCursor(); }
        if ($released === null || $released === false || DatabaseInteger::checked($released) !== 1) { throw new DatabaseFailure('busy'); }
    }
}
