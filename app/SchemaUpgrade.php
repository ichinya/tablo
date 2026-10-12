<?php
declare(strict_types=1);

namespace Tablo;

final class SchemaUpgrade
{
    public static function migrate(\PDO $db): void
    {
        $layout = SchemaLineage::classify($db);
        if ($layout['version'] === Database::CURRENT_SCHEMA_VERSION) { return; }
        if ($db->inTransaction()) { throw new \RuntimeException('Cannot migrate SQLite inside an existing transaction.'); }
        // BEGIN remains outside cleanup: a failed/nested BEGIN owns no transaction.
        $db->exec('BEGIN IMMEDIATE');
        try {
            $layout = SchemaLineage::classify($db);
            SchemaLayoutUpgrade::apply($db,$layout);
            SchemaShape::assert($db, SchemaReference::reference(Database::CURRENT_SCHEMA_VERSION, ['endpoint' => true]));
            if (SchemaShape::rows($db, 'PRAGMA main.foreign_key_check') !== []) { SchemaShape::refuse(); }
            $db->exec('PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION);
            $db->exec('COMMIT');
        } catch (\Throwable $error) {
            try { $db->exec('ROLLBACK'); }
            catch (\Throwable $discardedCleanup) { /* Preserve the first error even when raw SQL transaction state is invisible to PDO. */  unset($discardedCleanup); }
            throw $error;
        }
    }
}
