<?php
declare(strict_types=1);

namespace Tablo;

final class SiteResultSettlement
{
    public function __construct(private readonly \PDO $db,#[\SensitiveParameter] private readonly TokenVault $tokens,private readonly SiteCheckWriter $writes,private readonly ?\Closure $notificationClock) {}
    public function persistCheck(#[\SensitiveParameter] array $site, array $state, SettlementKind $kind): bool
    {
        // A failed BEGIN owns nothing, including a caller's existing transaction.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->writes->updateCheck($site, $state)) {
                $this->db->exec('ROLLBACK');
                return false;
            }
            $row = CheckHistorySnapshot::project($site, $state);
            $fields = array_keys($row);
            $statement = $this->db->prepare('INSERT INTO check_history (' . implode(', ', $fields)
                . ') VALUES (' . implode(', ', array_fill(0, count($fields), value: '?')) . ')');
            try { $statement->execute(array_values($row)); }
            finally { $statement->closeCursor(); }
            // Capture the real AUTOINCREMENT identity before any other INSERT.
            $historyId = (int) $this->db->lastInsertId();
            (new IncidentProjector($this->db))->accepted($historyId, $row);
            (new NotificationProjector($this->db, $this->tokens))->accepted($historyId,$row,$site,
                $this->notificationClock === null ? time() : ($this->notificationClock)());
            if ($kind === SettlementKind::Worker) {
                (new WorkerServiceCredit($this->writes))->apply($site,$state);
            }
            $this->db->exec('COMMIT');
            return true;
        } catch (\Throwable $error) {
            // SQL engine ownership, not PHP82's cached inTransaction flag, governs cleanup.
            try { $this->db->exec('ROLLBACK'); }
            catch (\Throwable $discardedCleanup) { /* Preserve the first validation/SQL/COMMIT/cleanup error. */  unset($discardedCleanup); }
            throw $error;
        }
    }
}
