<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationStatus
{
    public function __construct(private readonly \PDO $db) {}
    public function readStatus(?int $siteId): array
    {
        $statement=\Tablo\SqlStatement::checked($this->db->prepare('SELECT site_id,event,status,attempts,due_at,expires_at,last_code,http_status,acknowledged_at,coalesced
            FROM notification_slots'.($siteId===null?'':' WHERE site_id=?').' ORDER BY site_id,event LIMIT 50'));
        try { $statement->execute($siteId===null?[]:[$siteId]); return \Tablo\SqlRows::all($statement->fetchAll(), \Tablo\SqlNotificationSlotsAcknowledgedAtAttemptsCoalescedRow::checked(...)); } finally { $statement->closeCursor(); }
    }
    public function statusTruncated(): bool
    {
        $statement=\Tablo\SqlStatement::checked($this->db->query('SELECT 1 FROM notification_slots ORDER BY site_id,event LIMIT 1 OFFSET 50'));
        try { return $statement->fetchColumn()!==false; } finally { $statement->closeCursor(); }
    }
}
