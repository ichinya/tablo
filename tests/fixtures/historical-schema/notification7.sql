-- Frozen source c809053280e793649797624cdcf3845bb0235a07 database/schema.sql and app/sqlite-database.php
CREATE TABLE check_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            config_revision INTEGER NOT NULL CHECK (typeof(config_revision) = 'integer' AND config_revision >= 0),
            checked_at TEXT NOT NULL CHECK (typeof(checked_at) = 'text' AND length(CAST(checked_at AS BLOB)) = 20
                AND checked_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'
                AND substr(checked_at, 1, 4) <> '0000'
                AND strftime('%Y-%m-%dT%H:%M:%SZ', checked_at, '+0 seconds') IS checked_at),
            online INTEGER CHECK (online IS NULL OR (typeof(online) = 'integer' AND online IN (0, 1))),
            health_error_code TEXT CHECK (health_error_code IN ('http', 'timeout', 'refused', 'dns', 'ssrf', 'tls', 'size',
                'invalid-response', 'invalid-url', 'network', 'check-error', 'json-condition')),
            health_http_status INTEGER CHECK (health_http_status IS NULL OR (typeof(health_http_status) = 'integer' AND health_http_status BETWEEN 100 AND 599)),
            response_time_ms INTEGER CHECK (response_time_ms IS NULL OR (typeof(response_time_ms) = 'integer' AND response_time_ms BETWEEN 0 AND 2147483647)),
            deployed_version TEXT CHECK (deployed_version IS NULL OR (typeof(deployed_version) = 'text' AND length(CAST(deployed_version AS BLOB)) BETWEEN 1 AND 200)),
            deployed_commit TEXT CHECK (deployed_commit IS NULL OR (typeof(deployed_commit) = 'text' AND length(deployed_commit) BETWEEN 7 AND 64 AND deployed_commit NOT GLOB '*[^a-f0-9]*')),
            latest_release TEXT CHECK (latest_release IS NULL OR (typeof(latest_release) = 'text' AND length(CAST(latest_release AS BLOB)) BETWEEN 1 AND 200)),
            latest_commit TEXT CHECK (latest_commit IS NULL OR (typeof(latest_commit) = 'text' AND length(latest_commit) BETWEEN 7 AND 64 AND latest_commit NOT GLOB '*[^a-f0-9]*')),
            version_status TEXT NOT NULL CHECK (version_status IN ('skipped', 'ok', 'error')),
            version_error_code TEXT CHECK (version_error_code IN ('http', 'invalid-data', 'timeout', 'refused', 'dns', 'ssrf', 'tls', 'size',
                'invalid-response', 'invalid-url', 'network', 'check-error', 'json-condition')),
            version_http_status INTEGER CHECK (version_http_status IS NULL OR (typeof(version_http_status) = 'integer' AND version_http_status BETWEEN 100 AND 599)),
            release_error_code TEXT CHECK (release_error_code IN ('access', 'rate-limit', 'budget', 'credential-changed', 'renamed',
                'branch-unconfirmed', 'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable', 'check-error')),
            release_http_status INTEGER CHECK (release_http_status IS NULL OR (typeof(release_http_status) = 'integer' AND release_http_status BETWEEN 100 AND 599)),
            commit_error_code TEXT CHECK (commit_error_code IN ('access', 'rate-limit', 'budget', 'credential-changed', 'renamed',
                'branch-unconfirmed', 'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable', 'check-error')),
            commit_http_status INTEGER CHECK (commit_http_status IS NULL OR (typeof(commit_http_status) = 'integer' AND commit_http_status BETWEEN 100 AND 599)),
            CHECK ((version_status = 'error') = (version_error_code IS NOT NULL))
        );
CREATE TABLE git_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE,
    provider TEXT NOT NULL,
    encrypted_token TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    UNIQUE (provider, name)
);
CREATE TABLE github_cooldowns (
            scope TEXT NOT NULL,
            resource TEXT NOT NULL CHECK (resource IN ('core', 'search', 'secondary')),
            eligible_at INTEGER NOT NULL CHECK (typeof(eligible_at) = 'integer' AND eligible_at >= 0),
            PRIMARY KEY (scope, resource)
        );
CREATE TABLE incident_checkpoints (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            last_history_id INTEGER NOT NULL CHECK (last_history_id > 0),
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            watermark TEXT NOT NULL
        );
CREATE TABLE incidents (
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
        );
CREATE TABLE installation_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            check_interval_minutes INTEGER NOT NULL DEFAULT 10 CHECK (typeof(check_interval_minutes) = 'integer' AND check_interval_minutes > 0)
        );
CREATE TABLE login_limits (
    key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL
);
CREATE TABLE notification_checkpoints (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            last_history_id INTEGER NOT NULL CHECK (last_history_id > 0),
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            state TEXT NOT NULL CHECK (length(CAST(state AS BLOB)) <= 4096)
        );
CREATE TABLE notification_settings (
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
        );
CREATE TABLE notification_slots (
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
        );
CREATE TABLE sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    provider TEXT NOT NULL DEFAULT 'github' CHECK (provider = 'github'),
    repository TEXT NOT NULL,
    github_token TEXT,
    git_token_id INTEGER REFERENCES git_tokens(id) ON DELETE RESTRICT,
    branch TEXT NOT NULL DEFAULT 'main',
    health_path TEXT NOT NULL DEFAULT '/up',
    version_path TEXT NOT NULL DEFAULT '',
    version_json_path TEXT NOT NULL DEFAULT '',
    health_check_mode TEXT NOT NULL DEFAULT 'http' CHECK (health_check_mode IN ('http', 'json')),
    health_json_path TEXT NOT NULL DEFAULT '',
    health_json_operator TEXT NOT NULL DEFAULT '==' CHECK (health_json_operator IN ('>', '>=', '<', '<=', '!=', '==', 'contains')),
    health_json_expected_value TEXT NOT NULL DEFAULT '',
    comparison_mode TEXT NOT NULL DEFAULT 'release' CHECK (comparison_mode IN ('release', 'branch')),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    sort_order INTEGER NOT NULL DEFAULT 0,
    online INTEGER CHECK (online IN (0, 1)),
    deployed_version TEXT,
    deployed_commit TEXT,
    latest_release TEXT,
    latest_commit TEXT,
    open_issues INTEGER,
    open_prs INTEGER,
    response_time_ms INTEGER,
    last_error TEXT,
    checked_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
, health_error_code TEXT, health_http_status INTEGER CHECK (health_http_status BETWEEN 100 AND 599), config_revision INTEGER NOT NULL DEFAULT 0 CHECK (typeof(config_revision) = 'integer' AND config_revision >= 0));
CREATE TABLE users (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE TABLE worker_progress (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            latest_release INTEGER NOT NULL DEFAULT 0 CHECK (latest_release >= 0),
            latest_commit INTEGER NOT NULL DEFAULT 0 CHECK (latest_commit >= 0),
            open_issues INTEGER NOT NULL DEFAULT 0 CHECK (open_issues >= 0),
            open_prs INTEGER NOT NULL DEFAULT 0 CHECK (open_prs >= 0)
        );
CREATE TABLE worker_runtime (
            id INTEGER PRIMARY KEY CHECK (id = 1), run_id TEXT,
            stop_requested INTEGER NOT NULL DEFAULT 0 CHECK (stop_requested IN (0, 1)),
            fairness_turn INTEGER NOT NULL DEFAULT 0 CHECK (typeof(fairness_turn) = 'integer' AND fairness_turn >= 0)
        );
CREATE INDEX check_history_site_time ON check_history(site_id, checked_at, id);
CREATE INDEX check_history_time ON check_history(checked_at, id);
CREATE INDEX incidents_site_id ON incidents(site_id, id);
CREATE UNIQUE INDEX incidents_unresolved ON incidents(site_id) WHERE end_reason IS NULL;
CREATE INDEX notification_due ON notification_slots(status,due_at);
CREATE INDEX notification_eligible ON notification_slots(status,channel_revision,expires_at,due_at);
PRAGMA user_version=7;
