<?php
declare(strict_types=1);

namespace Tablo;

final class ReadApiConnection
{
    public static function open(#[\SensitiveParameter] ?string $path = null): \PDO
    {
        $db = ExistingConnection::openExistingConnection($path);
        $db->exec('PRAGMA query_only = ON');
        $statement = SqlStatement::checked($db->query('PRAGMA query_only'));
        try {
            /** @var int $enabled The native query_only pragma is an integer boolean. */
            $enabled = $statement->fetchColumn();
        }
        finally { $statement->closeCursor(); }
        if ($enabled !== 1 || SchemaVersion::schemaVersion($db) !== Database::CURRENT_SCHEMA_VERSION) {
            throw new \RuntimeException('Service unavailable.');
        }
        return $db;
    }
}
