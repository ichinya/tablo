<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationProjectionStore
{
    public function __construct(private readonly \PDO $db) {}
    public function server(): bool { return DatabaseSession::server($this->db); }
    public function one(string $sql, array $values=[]): array|false
    {
        $query=\Tablo\SqlStatement::checked($this->db->prepare($sql));
        try { $query->execute($values); return SqlRows::one($query->fetch(), static fn (array $row): array => $row); } finally { $query->closeCursor(); }
    }
    public function execute(string $sql, #[\SensitiveParameter] array $values): void
    {
        ServerWriterAdmission::borrow($this->db);
        $query=\Tablo\SqlStatement::checked($this->db->prepare($sql));
        try { $query->execute($values); } finally { $query->closeCursor(); }
    }

    /** @param array{int,string,string,int,int,int,string,?string,int,int,int} $values */
    public function enqueue(#[\SensitiveParameter] array $values): void
    {
        if (!$this->server()) {
            $this->execute("INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,
                created_at,expires_at,due_at,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
                ON CONFLICT(site_id,event) DO UPDATE SET event_id=excluded.event_id,source_id=excluded.source_id,
                config_revision=excluded.config_revision,channel_revision=excluded.channel_revision,payload=excluded.payload,
                private_cipher=excluded.private_cipher,created_at=excluded.created_at,expires_at=excluded.expires_at,due_at=excluded.due_at,
                coalesced=notification_slots.coalesced+CASE WHEN notification_slots.status IN ('pending','inflight') THEN 1 ELSE 0 END,
                status='pending',attempts=0,claim=NULL,lease_until=NULL,last_code=NULL,http_status=NULL,acknowledged_at=NULL
                WHERE notification_slots.event_id <> excluded.event_id", $values);
            return;
        }
        $current = $this->one('SELECT event_id FROM notification_slots WHERE site_id = ? AND event = ?', [$values[0], $values[1]]);
        if ($current !== false && ($current['event_id'] ?? null) === $values[2]) { return; }
        $this->execute("INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,created_at,expires_at,due_at,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE event_id=?,source_id=?,config_revision=?,channel_revision=?,payload=?,private_cipher=?,created_at=?,expires_at=?,due_at=?,
            coalesced=coalesced+CASE WHEN status IN ('pending','inflight') THEN 1 ELSE 0 END,status='pending',attempts=0,claim=NULL,lease_until=NULL,last_code=NULL,http_status=NULL,acknowledged_at=NULL",
            [...$values, ...array_slice($values, offset: 2)]);
    }
}
