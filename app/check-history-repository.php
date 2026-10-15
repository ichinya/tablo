<?php
declare(strict_types=1);

namespace Tablo;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Record from SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow as HistoryRow */
final class CheckHistoryRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @return array{rows: list<HistoryRow>, next_cursor: ?string} */
    public function page(int $siteId, string $from, string $to, int $limit = 50, ?string $cursor = null): array
    {
        $from = HistoryTime::canonical($from);
        $to = HistoryTime::canonical($to);
        if ($from >= $to || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('Invalid history query.'); }
        HistorySite::existingSite($this->db, $siteId);
        $position = $cursor === null ? null : HistoryCursor::decodeCursor($cursor, $siteId, $from, $to);
        $ceiling = HistoryPageCeiling::read($this->db,$position);
        $sql = 'SELECT * FROM check_history WHERE site_id = :site AND checked_at >= :from AND checked_at < :to
            AND id <= :ceiling';
        if ($position !== null) { $sql .= ' AND (checked_at, id) < (:time, :id)'; }
        $statement = \Tablo\SqlStatement::checked($this->db->prepare($sql . ' ORDER BY checked_at DESC, id DESC LIMIT :limit'));
        $statement->bindValue(':site', $siteId, PDO::PARAM_INT);
        $statement->bindValue(':from', $from, PDO::PARAM_STR);
        $statement->bindValue(':to', $to, PDO::PARAM_STR);
        $statement->bindValue(':ceiling', $ceiling, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        if ($position !== null) {
            $statement->bindValue(':time', $position['time'], PDO::PARAM_STR);
            $statement->bindValue(':id', $position['id'], PDO::PARAM_INT);
        }
        try { $statement->execute(); $rows = SqlRows::all($statement->fetchAll(), SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow::checked(...)); }
        finally { $statement->closeCursor(); }
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            /** @var non-empty-list<HistoryRow> $rows */
            $last = $rows[array_key_last($rows)] ?? throw new \LogicException('Missing retained history page row');
            $next = rtrim(strtr(base64_encode(JsonEncoding::encode(['site' => $siteId, 'from' => $from, 'to' => $to,
                'time' => $last['checked_at'], 'id' => $last['id'], 'ceiling' => $ceiling])), from: '+/', to: '-_'), characters: '=');
        }
        return ['rows' => $rows, 'next_cursor' => $next];
    }










    public function prune(int $days = 30, ?int $siteId = null, ?DateTimeImmutable $now = null): array { return (new HistoryPruner($this->db))->prune($days, $siteId, $now); }
    public function pruneBatch(string $cutoff, ?int $siteId = null): int { return (new HistoryPruner($this->db))->pruneBatch($cutoff, $siteId); }
}
