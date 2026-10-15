<?php
declare(strict_types=1);

namespace Tablo;

final class IncidentTransition
{
    public function __construct(private readonly \PDO $db) {}
    public function apply(IncidentObservation $observation,int|string|false $openId): void
    {
        $historyId=$observation->historyId; $row=$observation->row; $older=$observation->older; $siteId=$row['site_id'];
        if ($openId === false && $row['online'] === 0) {
            $statement = \Tablo\SqlStatement::checked($this->db->prepare('INSERT INTO incidents
                (id, site_id, config_revision, opened_at, health_error_code, health_http_status, uncertain, clock_invalid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)'));
            try { $statement->execute([$historyId, $siteId, $row['config_revision'], $row['checked_at'],
                $row['health_error_code'], $row['health_http_status'], (int) $older, (int) $older]); }
            finally { $statement->closeCursor(); }
            return;
        }
        if ($openId === false) { return; }
        $sql = 'UPDATE incidents SET uncertain = MAX(uncertain, ?), clock_invalid = MAX(clock_invalid, ?) WHERE id = ?';
        $values = [(int) ($row['online'] === null || $older), (int) $older, $openId];
        if ($row['online'] === 1 && !$older) {
            $sql = "UPDATE incidents SET end_reason = 'recovered', recovery_history_id = ?, recovered_at = ? WHERE id = ?";
            $values = [$historyId, $row['checked_at'], $openId];
        }
            $statement = SqlStatement::checked($this->db->prepare($sql));
            try { $statement->execute($values); }
            finally { $statement->closeCursor(); }
    }
}
