<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryPruner
{
    public function __construct(private readonly \PDO $db) {}
    /** @return (array{'capped': false, 'deleted': int})|(array{'capped': true, 'deleted': int}) */
    public function prune(int $days = 30, ?int $siteId = null, ?\DateTimeImmutable $now = null): array
    {
        $cutoff = HistoryRetention::cutoff($days, $now); // Capture once, before destructive work.
        if ($siteId !== null) { HistorySite::existingSite($this->db, $siteId); }
        $deleted = 0;
        for ($batch = 0; $batch < 10; $batch++) {
            $count = $this->pruneBatch($cutoff, $siteId);
            $deleted += $count;
            if ($count < 500) { return ['deleted' => $deleted, 'capped' => false]; }
        }
        return ['deleted' => $deleted, 'capped' => true];
    }
    public function pruneBatch(string $cutoff, ?int $siteId = null): int
    {
        $cutoff = HistoryTime::canonical($cutoff);
        if ($siteId !== null) { HistorySite::existingSite($this->db, $siteId); }
        $transaction = WriteTransaction::begin($this->db); // Failed BEGIN never rolls back caller-owned writes.
        try {
            $server = DatabaseSession::server($this->db);
            $sql = $server ? 'SELECT id FROM check_history WHERE checked_at < :cutoff' : 'DELETE FROM check_history WHERE id IN (SELECT id FROM check_history WHERE checked_at < :cutoff';
            if ($siteId !== null) { $sql .= ' AND site_id = :site'; }
            $statement = \Tablo\SqlStatement::checked($this->db->prepare($sql . ' ORDER BY checked_at, id LIMIT :limit' . ($server ? '' : ')')));
            $statement->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
            $statement->bindValue(':limit', 500, \PDO::PARAM_INT);
            if ($siteId !== null) { $statement->bindValue(':site', $siteId, \PDO::PARAM_INT); }
            try {
                $statement->execute();
                $deleted = $statement->rowCount();
                if ($server) {
                    $ids = array_values(array_map(DatabaseInteger::checked(...), $statement->fetchAll(\PDO::FETCH_COLUMN)));
                    $statement->closeCursor();
                    $deleted = 0;
                    if ($ids !== []) {
                        $delete = SqlStatement::checked($this->db->prepare('DELETE FROM check_history WHERE id IN (' . implode(',', array_fill(0, count($ids), value: '?')) . ')'));
                        try {
                            foreach ($ids as $index => $id) { $delete->bindValue($index + 1, $id, \PDO::PARAM_INT); }
                            $delete->execute(); $deleted = $delete->rowCount();
                        } finally { $delete->closeCursor(); }
                    }
                }
            }
            finally { $statement->closeCursor(); }
            $transaction->commit();
            return $deleted;
        } catch (\Throwable $error) {
            $transaction->rollbackPreserving();
            throw $error;
        }
    }

}
