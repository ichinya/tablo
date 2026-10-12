<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryPruner
{
    public function __construct(private readonly \PDO $db) {}
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
        $this->db->exec('BEGIN IMMEDIATE'); // Failed BEGIN never rolls back caller-owned writes.
        try {
            $sql = 'DELETE FROM check_history WHERE id IN (SELECT id FROM check_history WHERE checked_at < :cutoff';
            if ($siteId !== null) { $sql .= ' AND site_id = :site'; }
            $statement = $this->db->prepare($sql . ' ORDER BY checked_at, id LIMIT :limit)');
            $statement->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
            $statement->bindValue(':limit', 500, \PDO::PARAM_INT);
            if ($siteId !== null) { $statement->bindValue(':site', $siteId, \PDO::PARAM_INT); }
            try { $statement->execute(); $deleted = $statement->rowCount(); }
            finally { $statement->closeCursor(); }
            $this->db->exec('COMMIT');
            return $deleted;
        } catch (\Throwable $error) {
            try { $this->db->exec('ROLLBACK'); }
            catch (\Throwable $discardedCleanup) { /* Preserve the original batch failure; prior batches stay committed. */  unset($discardedCleanup); }
            throw $error;
        }
    }

}
