<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Read from AvailabilityData */
final class AvailabilityRepository
{
    public const MAXIMUM_ROWS = 12_000;

    public function __construct(private readonly PDO $db) {}

    /** One bounded statement observes retained samples; no snapshot persists across requests.
     * @return Read */
    public function read(int $siteId, string $from, string $to): array
    {
        $from = HistoryTime::canonical($from); $to = HistoryTime::canonical($to);
        if ($siteId < 1 || $from >= $to) { throw new \InvalidArgumentException('Invalid availability query.'); }
        $metadata = SqlStatement::checked($this->db->prepare('SELECT id, name, enabled, config_revision AS current_revision FROM sites WHERE id = :site'));
        try {
            $metadata->bindValue(':site', $siteId, PDO::PARAM_INT); $metadata->execute();
            $site = SqlRows::one($metadata->fetch(PDO::FETCH_ASSOC), AvailabilityData::site(...));
            if ($site === false) { throw new \OutOfBoundsException('Availability site not found.'); }
        } finally { $metadata->closeCursor(); }
        $statement = SqlStatement::checked($this->db->prepare('SELECT id, config_revision, checked_at, online, response_time_ms
            FROM check_history WHERE site_id = :site AND checked_at >= :from AND checked_at < :to
            ORDER BY checked_at ASC, id ASC LIMIT :limit'));
        try {
            $statement->bindValue(':site', $siteId, PDO::PARAM_INT);
            $statement->bindValue(':from', $from, PDO::PARAM_STR); $statement->bindValue(':to', $to, PDO::PARAM_STR);
            $statement->bindValue(':limit', self::MAXIMUM_ROWS + 1, PDO::PARAM_INT); $statement->execute();
            $rows = SqlRows::all($statement->fetchAll(PDO::FETCH_ASSOC), AvailabilityData::sample(...));
        } finally { $statement->closeCursor(); }
        $overflow = count($rows) > self::MAXIMUM_ROWS;
        return ['site' => $site, 'overflow' => $overflow, 'rows' => $overflow ? [] : $rows];
    }
}
