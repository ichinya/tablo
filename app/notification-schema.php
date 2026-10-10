<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class NotificationSchema
{
    // Additive, prospective installation. No old row/history/incident is replayed.
    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE notification_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            installation_id TEXT NOT NULL CHECK (length(installation_id) = 32),
            revision INTEGER NOT NULL DEFAULT 0 CHECK (revision >= 0),
            activation_id INTEGER NOT NULL DEFAULT 0 CHECK (activation_id >= 0),
            enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0,1)),
            blocked INTEGER NOT NULL DEFAULT 0 CHECK (blocked IN (0,1)),
            endpoint_cipher TEXT, bearer_cipher TEXT,
            unavailable INTEGER NOT NULL DEFAULT 1 CHECK (unavailable IN (0,1)),
            recovery INTEGER NOT NULL DEFAULT 1 CHECK (recovery IN (0,1)),
            version_lag INTEGER NOT NULL DEFAULT 1 CHECK (version_lag IN (0,1)),
            include_name INTEGER NOT NULL DEFAULT 0 CHECK (include_name IN (0,1)),
            include_url INTEGER NOT NULL DEFAULT 0 CHECK (include_url IN (0,1))
        )");
        $db->prepare('INSERT INTO notification_settings (id, installation_id) VALUES (1, ?)')->execute([bin2hex(random_bytes(16))]);
        $db->exec("CREATE TABLE notification_checkpoints (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            last_history_id INTEGER NOT NULL CHECK (last_history_id > 0),
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            state TEXT NOT NULL CHECK (length(CAST(state AS BLOB)) <= 4096)
        )");
        $db->exec("CREATE TABLE notification_slots (
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            event TEXT NOT NULL CHECK (event IN ('unavailable','recovery','version_lag')),
            event_id TEXT NOT NULL CHECK (length(event_id) <= 128),
            source_id INTEGER NOT NULL CHECK (source_id > 0),
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            channel_revision INTEGER NOT NULL CHECK (channel_revision >= 0),
            payload TEXT NOT NULL CHECK (length(CAST(payload AS BLOB)) <= 8192),
            private_cipher TEXT,
            created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, due_at INTEGER NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('pending','inflight','sent','failed','cancelled','expired')),
            attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 3),
            claim TEXT, lease_until INTEGER, last_code TEXT, http_status INTEGER CHECK (http_status BETWEEN 100 AND 599),
            acknowledged_at INTEGER,
            coalesced INTEGER NOT NULL DEFAULT 0 CHECK (coalesced >= 0),
            PRIMARY KEY (site_id,event)
        )");
        $db->exec('CREATE INDEX notification_due ON notification_slots(status,due_at)');
        $db->exec('CREATE INDEX notification_eligible ON notification_slots(status,channel_revision,expires_at,due_at)');
    }
}
