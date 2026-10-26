<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from ServerSchema
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerWriterAdmission
{
    public static function admit(PDO $db, string $purpose): void
    {
        ServerSchemaState::ready($db);
        $session = DatabaseSession::get($db);
        if ($session === null) { self::refuse(); }
        if ($session->configuration->role === 'control') {
            if ($purpose !== 'control') { throw new DatabaseFailure('key'); }
            return;
        }
        if ($session->configuration->role !== 'writer') { self::refuse(); }
        $vault = $session->vault;
        if ($vault === null) { throw new DatabaseFailure('key'); }
        ServerKeyIdentity::authenticate($db, $vault);
    }
    public static function borrow(PDO $db): void
    {
        if (DatabaseSession::server($db) && !WriteTransaction::owns($db)) { throw new \LogicException('Writer transaction owner required.'); }
    }
    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public static function single(PDO $db, \Closure $operation): mixed
    {
        return DatabaseSession::server($db) ? WriteTransaction::run($db, $operation) : $operation();
    }
    public static function start(PDO $db): void
    {
        $server = DatabaseSession::server($db);
        try { $db->exec($server ? 'START TRANSACTION' : 'BEGIN IMMEDIATE'); }
        catch (\PDOException $error) { if ($server) { throw DatabaseFailure::fromNative($error); } throw $error; }
    }
    public static function guard(PDO $db, string $purpose): void
    {
        self::lock($db);
        self::admit($db, $purpose);
    }
    /** Ordinary writes and maintenance use the same exact-PDO singleton lock. */
    public static function lock(PDO $db): void
    {
        if (!$db->inTransaction()) { throw new \LogicException('Active server transaction required.'); }
        $statement = SqlStatement::checked($db->query('SELECT id FROM tablo_writer_guard WHERE id = 1 FOR UPDATE'));
        try { $guard = DatabaseInteger::checked($statement->fetchColumn()); } finally { $statement->closeCursor(); }
        if ($guard !== 1) { self::refuse(); }
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}
