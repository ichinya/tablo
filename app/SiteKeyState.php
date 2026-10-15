<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class SiteKeyState
{
    public static function hasEstablishedKeyState(PDO $db): bool
    {
        // Direct repository construction also supports genuine pre-migration fixtures.
        // Inspect only known physical columns; a missing table is not a lost key.
        foreach ([['git_tokens', 'encrypted_token', "encrypted_token <> ''"],
            ['sites', 'github_token', "github_token <> ''"],
            ['sites', 'endpoint_token', "endpoint_token <> ''"],
            ['notification_settings', 'endpoint_cipher', 'endpoint_cipher IS NOT NULL OR bearer_cipher IS NOT NULL'],
            ['notification_slots', 'private_cipher', 'private_cipher IS NOT NULL'],
            ['github_cooldowns', 'scope', "scope LIKE 'credential:v1:%'"]] as [$table, $column, $predicate]) {
            $statement = \Tablo\SqlStatement::checked($db->query('PRAGMA main.table_info(' . $table . ')'));
            try {
                // PRAGMA table_info returns real metadata rows, including an empty list for an absent table.
                /** @var list<array{name: string, ...}> $rows */
                $rows = $statement->fetchAll();
                $columns = array_column($rows, 'name');
            }
            finally { $statement->closeCursor(); }
            if (!in_array($column, $columns, strict: true)) { continue; }
            $statement = \Tablo\SqlStatement::checked($db->query('SELECT EXISTS(SELECT 1 FROM main.' . $table . ' WHERE ' . $predicate . ')'));
            try {
                // SQLite EXISTS always projects one integer 0/1 row.
                /** @var 0|1 $exists */
                $exists = $statement->fetchColumn();
                if ((bool) $exists) { return true; }
            }
            finally { $statement->closeCursor(); }
        }
        return false;
    }


}
