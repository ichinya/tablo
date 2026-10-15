<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

final class IncidentRepository
{
    public function __construct(private readonly PDO $db) {}

    public function page(?int $siteId = null, int $limit = 50, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || ($siteId !== null && $siteId < 1)) { self::invalid(); }
        if ($siteId !== null) {
            $statement = $this->db->prepare('SELECT id FROM sites WHERE id = ?');
            try {
                $statement->execute([$siteId]);
                if ($statement->fetchColumn() === false) { throw new OutOfBoundsException('Incident site not found.'); }
            } finally { $statement->closeCursor(); }
        }
        $position = $cursor === null ? null : IncidentCursor::decode($cursor, $siteId);
        if ($position === null) {
            $statement = $this->db->query('SELECT MAX(id) FROM incidents');
            try { $ceiling = (int) $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
        } else { $ceiling = $position['ceiling']; }
        $sql = 'SELECT i.*, s.name AS site_name, s.enabled AS site_enabled, s.config_revision AS current_revision,
            c.watermark AS last_observed_at FROM incidents i JOIN sites s ON s.id = i.site_id
            JOIN incident_checkpoints c ON c.site_id = i.site_id WHERE i.id <= :ceiling';
        if ($siteId !== null) { $sql .= ' AND i.site_id = :site'; }
        if ($position !== null) { $sql .= ' AND i.id < :before'; }
        $statement = $this->db->prepare($sql . ' ORDER BY i.id DESC LIMIT :limit');
        $statement->bindValue(':ceiling', $ceiling, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        if ($siteId !== null) { $statement->bindValue(':site', $siteId, PDO::PARAM_INT); }
        if ($position !== null) { $statement->bindValue(':before', $position['before'], PDO::PARAM_INT); }
        try { $statement->execute(); $rows = $statement->fetchAll(); }
        finally { $statement->closeCursor(); }
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = IncidentCursor::encode(['v' => 1, 'site' => $siteId, 'before' => $rows[array_key_last($rows)]['id'], 'ceiling' => $ceiling]);
        }
        return ['rows' => $rows, 'next_cursor' => $next];
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid incident query.');
    }
}
