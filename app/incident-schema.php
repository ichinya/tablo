<?php
declare(strict_types=1);

namespace Tablo;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class IncidentSchema
{
    public const STATEMENTS = [
        "CREATE TABLE incidents (
            id INTEGER PRIMARY KEY CHECK (id > 0),
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            opened_at TEXT NOT NULL,
            health_error_code TEXT CHECK (health_error_code IN ('http', 'timeout', 'refused', 'dns', 'ssrf', 'tls', 'size',
                'invalid-response', 'invalid-url', 'network', 'check-error', 'json-condition')),
            health_http_status INTEGER CHECK (health_http_status BETWEEN 100 AND 599),
            recovery_history_id INTEGER CHECK (recovery_history_id > id),
            recovered_at TEXT,
            interruption_history_id INTEGER CHECK (interruption_history_id > id),
            end_reason TEXT CHECK (end_reason IN ('recovered', 'config-changed')),
            uncertain INTEGER NOT NULL DEFAULT 0 CHECK (uncertain IN (0, 1)),
            clock_invalid INTEGER NOT NULL DEFAULT 0 CHECK (clock_invalid IN (0, 1)),
            CHECK ((end_reason IS 'recovered') = (recovery_history_id IS NOT NULL AND recovered_at IS NOT NULL)),
            CHECK ((end_reason IS 'config-changed') = (interruption_history_id IS NOT NULL)),
            CHECK (end_reason IS 'recovered' OR (recovery_history_id IS NULL AND recovered_at IS NULL))
        )",
        'CREATE UNIQUE INDEX incidents_unresolved ON incidents(site_id) WHERE end_reason IS NULL',
        'CREATE INDEX incidents_site_id ON incidents(site_id, id)',
        "CREATE TABLE incident_checkpoints (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            last_history_id INTEGER NOT NULL CHECK (last_history_id > 0),
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            watermark TEXT NOT NULL
        )",
    ];

}
