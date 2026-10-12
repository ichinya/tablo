<?php
declare(strict_types=1);

namespace Tablo;

final class HistorySite
{
    public static function existingSite(\PDO $db, int $siteId): void
    {
        if ($siteId < 1) { throw new \InvalidArgumentException('Invalid history site.'); }
        $statement = $db->prepare('SELECT id FROM sites WHERE id = ?');
        try {
            $statement->execute([$siteId]);
            if ($statement->fetchColumn() === false) { throw new \OutOfBoundsException('History site not found.'); }
        } finally { $statement->closeCursor(); }
    }
}
