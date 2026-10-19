<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Config from SiteCheckWriter */
final class SiteResultSettlement
{
    /** @param (\Closure(): int)|null $notificationClock */
    public function __construct(private readonly \PDO $db,#[\SensitiveParameter] private readonly TokenVault $tokens,private readonly SiteCheckWriter $writes,private readonly ?\Closure $notificationClock) {}
    /** @param Config $site */
    public function persistCheck(#[\SensitiveParameter] array $site, array $state, SettlementKind $kind): bool
    {
        // A failed BEGIN owns nothing, including a caller's existing transaction.
        $transaction = WriteTransaction::begin($this->db);
        try {
            if (!$this->writes->updateCheck($site, $state)) {
                $transaction->rollback();
                return false;
            }
            $row = CheckHistorySnapshot::project($site, $state);
            $fields = array_keys($row);
            $statement = \Tablo\SqlStatement::checked($this->db->prepare('INSERT INTO check_history (' . implode(', ', $fields)
                . ') VALUES (' . implode(', ', array_fill(0, count($fields), value: '?')) . ')'));
            try { $statement->execute(array_values($row)); }
            finally { $statement->closeCursor(); }
            // Capture the real AUTOINCREMENT identity before any other INSERT.
            $historyId = DatabaseInteger::positive($this->db->lastInsertId());
            (new IncidentProjector($this->db))->accepted($historyId, $row);
            (new NotificationProjector($this->db, $this->tokens))->accepted($historyId,$row,$site,
                $this->notificationClock === null ? time() : ($this->notificationClock)());
            if ($kind === SettlementKind::Worker) {
                (new WorkerServiceCredit($this->writes))->apply($site,$state);
            }
            if (DatabaseSession::server($this->db)) {
                $statement = SqlStatement::checked($this->db->prepare('UPDATE tablo_database_state SET committed_history_high_watermark = GREATEST(committed_history_high_watermark, ?) WHERE id = 1'));
                try { $statement->bindValue(1, $historyId, \PDO::PARAM_INT); $statement->execute(); } finally { $statement->closeCursor(); }
            }
            $transaction->commit();
            return true;
        } catch (\Throwable $error) {
            // SQL engine ownership, not PHP82's cached inTransaction flag, governs cleanup.
            $transaction->rollbackPreserving();
            if (DatabaseSession::server($this->db) && $error instanceof \PDOException) { throw DatabaseFailure::fromNative($error); }
            throw $error;
        }
    }
}
