<?php
declare(strict_types=1);

namespace Tablo;

final class IncidentProjector
{
    public function __construct(private readonly \PDO $db) {}

    public function accepted(int $historyId, array $row): void
    {
        $siteId = $row['site_id'];
        $checkpoint = $this->db->prepare('SELECT * FROM incident_checkpoints WHERE site_id = ?');
        try { $checkpoint->execute([$siteId]); $previous = $checkpoint->fetch(); }
        finally { $checkpoint->closeCursor(); }
        if ($previous !== false && $historyId <= $previous['last_history_id']) { return; }
        $epochChanged = $previous !== false && $previous['config_revision'] !== $row['config_revision'];
        if ($epochChanged) {
            $statement = $this->db->prepare("UPDATE incidents SET end_reason = 'config-changed', interruption_history_id = ?
                WHERE site_id = ? AND end_reason IS NULL");
            try { $statement->execute([$historyId, $siteId]); }
            finally { $statement->closeCursor(); }
        }
        $watermark = $previous === false || $epochChanged ? $row['checked_at'] : $previous['watermark'];
        $older = $row['checked_at'] < $watermark;
        $statement = $this->db->prepare('SELECT id FROM incidents WHERE site_id = ? AND end_reason IS NULL');
        try { $statement->execute([$siteId]); $openId = $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        (new IncidentTransition($this->db))->apply(new IncidentObservation($historyId,$row,$older),$openId);
        $statement = $this->db->prepare('INSERT INTO incident_checkpoints (site_id, last_history_id, config_revision, watermark)
            VALUES (?, ?, ?, ?) ON CONFLICT(site_id) DO UPDATE SET last_history_id = excluded.last_history_id,
            config_revision = excluded.config_revision, watermark = excluded.watermark');
        try { $statement->execute([$siteId, $historyId, $row['config_revision'], max($watermark, $row['checked_at'])]); }
        finally { $statement->closeCursor(); }
    }


}
