<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

final class SchemaVersion
{
    public static function schemaVersion(PDO $db): int
    {
        $statement = $db->query('PRAGMA main.user_version');
        try { $version = (int) $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        if ($version < 0 || $version > Database::CURRENT_SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported SQLite schema version ' . $version
                . '; this application supports version ' . Database::CURRENT_SCHEMA_VERSION . '.');
        }
        return $version;
    }
}
