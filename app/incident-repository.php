<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/**
 * @phpstan-type Row array{clock_invalid: int, config_revision: int, end_reason: ?string, health_error_code: ?string,
 * health_http_status: ?int, id: int, interruption_history_id: ?int, opened_at: string, recovered_at: ?string,
 * recovery_history_id: ?int, site_id: int, uncertain: int, site_name: string, site_enabled: int,
 * current_revision: int, last_observed_at: string, ...}
 */
final class IncidentRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @return array{rows: list<Row>, next_cursor: ?string} */
    public function page(?int $siteId = null, int $limit = 50, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || ($siteId !== null && $siteId < 1)) { self::invalid(); }
        if ($siteId !== null) {
            $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT id FROM sites WHERE id = ?'));
            try {
                $statement->execute([$siteId]);
                if ($statement->fetchColumn() === false) { throw new OutOfBoundsException('Incident site not found.'); }
            } finally { $statement->closeCursor(); }
        }
        $position = $cursor === null ? null : IncidentCursor::decode($cursor, $siteId);
        $ceiling = IncidentPageCeiling::read($this->db,$position);
        $sql = 'SELECT i.*, s.name AS site_name, s.enabled AS site_enabled, s.config_revision AS current_revision,
            c.watermark AS last_observed_at FROM incidents i JOIN sites s ON s.id = i.site_id
            JOIN incident_checkpoints c ON c.site_id = i.site_id WHERE i.id <= :ceiling';
        if ($siteId !== null) { $sql .= ' AND i.site_id = :site'; }
        if ($position !== null) { $sql .= ' AND i.id < :before'; }
        $statement = \Tablo\SqlStatement::checked($this->db->prepare($sql . ' ORDER BY i.id DESC LIMIT :limit'));
        $statement->bindValue(':ceiling', $ceiling, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        if ($siteId !== null) { $statement->bindValue(':site', $siteId, PDO::PARAM_INT); }
        if ($position !== null) { $statement->bindValue(':before', $position['before'], PDO::PARAM_INT); }
        try {
            $statement->execute();
            // This fixed inner join projects all incident columns and non-null site/checkpoint metadata.
            /** @var list<Row> $rows */
            $rows = \Tablo\SqlRows::all($statement->fetchAll(), \Tablo\SqlSitesIdRow::checked(...));
        }
        finally { $statement->closeCursor(); }
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            // A validated limit >= 1 and a batch larger than that limit leave at least one row.
            /** @var non-empty-list<Row> $rows */
            $last = $rows[array_key_last($rows)] ?? throw new \LogicException('Missing retained incident page row');
            $next = IncidentCursor::encode(['v' => 1, 'site' => $siteId, 'before' => $last['id'], 'ceiling' => $ceiling]);
        }
        return ['rows' => $rows, 'next_cursor' => $next];
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid incident query.');
    }
}
