CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE IF NOT EXISTS git_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE,
    provider TEXT NOT NULL,
    encrypted_token TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    UNIQUE (provider, name)
);

CREATE TABLE IF NOT EXISTS sites (
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
);

CREATE TABLE IF NOT EXISTS login_limits (
    key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL
);
