<?php
declare(strict_types=1);

namespace Tablo;

final class IncidentPageCeiling
{
    /** @param array{ceiling: int, ...}|null $position */
    public static function read(\PDO $db,?array $position): int
    {
        if ($position !== null) { return $position['ceiling']; }
        $statement=\Tablo\SqlStatement::checked($db->query('SELECT MAX(id) FROM incidents'));
        try { return (int)$statement->fetchColumn(); } finally { $statement->closeCursor(); }
    }
}
