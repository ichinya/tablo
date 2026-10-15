<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryPageCeiling
{
    /** @param array{ceiling: int, ...}|null $position */
    public static function read(\PDO $db, ?array $position): int
    {
        if ($position !== null) { return $position['ceiling']; }
        $statement = \Tablo\SqlStatement::checked($db->query('SELECT MAX(id) FROM check_history'));
        try { return (int) $statement->fetchColumn(); } finally { $statement->closeCursor(); }
    }
}
