<?php
declare(strict_types=1);

namespace Tablo;

final class ServerMigrationLock
{
    /**
     * @template T
     * @param \Closure(ServerLock): T $operation
     * @return T
     */
    public static function run(\PDO $db, #[\SensitiveParameter] TokenVault $vault, \Closure $operation): mixed
    {
        if (DatabaseSession::get($db)?->configuration->role !== 'migrator' || !$vault->isExternal()) { throw new DatabaseFailure('configuration'); }
        $vault->assertAvailable(KeyRequirement::Existing);
        $lock = new ServerLock();
        if (!$lock->acquire($db, 'migration', timeout: 5)) { throw new DatabaseFailure('busy'); }
        try { $result = $operation($lock); }
        catch (\Throwable $error) {
            try { $lock->close(); } catch (\Throwable $discarded) { unset($discarded); }
            if ($error instanceof \PDOException) { throw DatabaseFailure::fromNative($error); }
            throw $error;
        }
        $lock->close();
        return $result;
    }

}
