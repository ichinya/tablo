-- Frozen source f57c03a4e36edbc1f9a703170c82bc5a0dbad3ff database/schema.sql and app/sqlite-database.php
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
CREATE TABLE login_limits (
    key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL
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
, health_error_code TEXT, health_http_status INTEGER CHECK (health_http_status BETWEEN 100 AND 599));
CREATE TABLE users (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
PRAGMA user_version=3;
