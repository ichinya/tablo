<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use WeakMap;

/** One short writer owner. Network work must finish before begin. */
final class WriteTransaction
{
    /** @var WeakMap<PDO, self>|null */
    private static ?WeakMap $owners = null;
    private bool $active = true;

    private function __construct(private readonly PDO $db) {}

    public static function begin(PDO $db, string $purpose = 'write'): self
    {
        self::$owners ??= new WeakMap();
        $server = DatabaseSession::server($db);
        if (self::owns($db) || ($server && $db->inTransaction())) { throw new \LogicException('Caller owns database transaction.'); }
        // A failed BEGIN owns nothing, including raw SQLite transactions on PHP8.2.
        // SQLite keeps its native failed-BEGIN exception for an existing caller transaction.
        ServerWriterAdmission::start($db);
        $owner = new self($db);
        self::$owners[$db] = $owner;
        if ($server) {
            try {
                ServerWriterAdmission::guard($db, $purpose);
            } catch (\Throwable $error) {
                $owner->rollbackPreserving();
                DatabaseFailure::rethrow($db, $error);
            }
        }
        return $owner;
    }

    public static function owns(PDO $db): bool { return (self::$owners[$db] ?? null) !== null; }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public static function run(PDO $db, \Closure $operation, string $purpose = 'write'): mixed
    {
        if (self::owns($db)) { return $operation(); }
        $owner = self::begin($db, $purpose);
        try { $result = $operation(); $owner->commit(); return $result; }
        catch (\Throwable $error) {
            $owner->rollbackPreserving();
            DatabaseFailure::rethrow($db, $error);
        }
    }

    public function commit(): void
    {
        $this->assertOwner();
        try { $this->db->exec('COMMIT'); }
        catch (\PDOException $error) {
            if (DatabaseSession::server($this->db)) { throw new DatabaseFailure('uncertain'); }
            throw $error;
        }
        $this->release();
    }

    public function rollback(): void
    {
        $this->assertOwner();
        try { $this->db->exec('ROLLBACK'); } finally { $this->release(); }
    }

    public function rollbackPreserving(): void
    {
        if (!$this->active) { return; }
        try { $this->rollback(); } catch (\Throwable $discarded) { unset($discarded); }
    }

    private function assertOwner(): void
    {
        if ((self::$owners[$this->db] ?? null) !== $this) { throw new \LogicException('Database transaction ownership lost.'); }
    }

    private function release(): void { unset(self::$owners[$this->db]); $this->active = false; }
}
