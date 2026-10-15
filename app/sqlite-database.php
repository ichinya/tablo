<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    public const CURRENT_SCHEMA_VERSION = 6;

    // Prospective activation: these additive DDL statements never read current values or retained history.
    private const INCIDENT_SCHEMA = [
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

    // Version 0 installations may lack these additive fields and git_tokens.
    private const SITE_ADDITIONS = [
        'github_token' => 'TEXT',
        'git_token_id' => 'INTEGER REFERENCES git_tokens(id) ON DELETE RESTRICT',
        'version_json_path' => "TEXT NOT NULL DEFAULT ''",
        'health_check_mode' => "TEXT NOT NULL DEFAULT 'http' CHECK (health_check_mode IN ('http', 'json'))",
        'health_json_path' => "TEXT NOT NULL DEFAULT ''",
        'health_json_operator' => "TEXT NOT NULL DEFAULT '==' CHECK (health_json_operator IN ('>', '>=', '<', '<=', '!=', '==', 'contains'))",
        'health_json_expected_value' => "TEXT NOT NULL DEFAULT ''",
    ];

    private const BASE_COLUMNS = [
        'users' => ['id', 'password_hash', 'created_at'],
        'git_tokens' => ['id', 'name', 'provider', 'encrypted_token', 'created_at', 'updated_at'],
        'sites' => ['id', 'name', 'url', 'provider', 'repository', 'branch', 'health_path', 'version_path',
            'comparison_mode', 'enabled', 'sort_order', 'online', 'deployed_version', 'deployed_commit',
            'latest_release', 'latest_commit', 'open_issues', 'open_prs', 'response_time_ms', 'last_error',
            'checked_at', 'created_at', 'updated_at'],
        'login_limits' => ['key', 'failures', 'window_start'],
    ];

    public static function connect(?string $path = null): PDO
    {
        $path ??= getenv('TABLO_DB') ?: dirname(__DIR__) . '/storage/tablo.sqlite';
        // SQLite file: URIs are not filesystem paths for PHP's directory functions.
        if ($path !== ':memory:' && !str_starts_with($path, 'file:') && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        self::migrate($db);
        if ($path !== ':memory:') {
            // Configure only accepted schemas; rejected future databases keep their journal metadata.
            // busy_timeout is already installed before this potentially contended mode transition.
            $statement = $db->query('PRAGMA journal_mode = WAL');
            try { $mode = $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
            if ($mode !== 'wal') {
                throw new RuntimeException('SQLite WAL is required for file databases; use writable local storage.');
            }
            @chmod($path, 0600);
        }
        return $db;
    }

    public static function migrate(PDO $db): void
    {
        if (self::schemaVersion($db) === self::CURRENT_SCHEMA_VERSION) { return; }
        if ($db->inTransaction()) {
            throw new RuntimeException('Cannot migrate SQLite inside an existing transaction.');
        }

        // Acquire before inspecting/changing the legacy schema. A failed BEGIN owns no transaction.
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Another process may have completed the migration while BEGIN waited.
            $version = self::schemaVersion($db);
            while ($version < self::CURRENT_SCHEMA_VERSION) {
                if ($version === 5) {
                    foreach (self::INCIDENT_SCHEMA as $statement) { $db->exec($statement); }
                } else {
                    match ($version) {
                        0 => self::migrateToVersionOne($db),
                        1 => self::migrateToVersionTwo($db),
                        2 => self::migrateToVersionThree($db),
                        3 => self::migrateToVersionFour($db),
                        4 => self::migrateToVersionFive($db),
                        default => throw new RuntimeException('No migration for SQLite schema version ' . $version),
                    };
                }
                $version++;
                $db->exec('PRAGMA main.user_version = ' . $version);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                // BEGIN succeeded above, so this is our transaction. PHP 8.2 may not report
                // SQL-started transactions in inTransaction(); always attempt the rollback.
                $db->exec('ROLLBACK');
            } catch (Throwable) {
                // Preserve the migration error if SQLite already rolled back or the rollback fails.
            }
            throw $error;
        }
    }

    private static function schemaVersion(PDO $db): int
    {
        $statement = $db->query('PRAGMA main.user_version');
        try { $version = (int) $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        if ($version < 0 || $version > self::CURRENT_SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported SQLite schema version ' . $version
                . '; this application supports version ' . self::CURRENT_SCHEMA_VERSION . '.');
        }
        return $version;
    }

    private static function migrateToVersionOne(PDO $db): void
    {
        self::validateSchema($db, false);
        // schema.sql is the frozen version 1 bootstrap; future versions need separate transitions.
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($schema === false) { throw new RuntimeException('Cannot read SQLite bootstrap schema.'); }
        $db->exec($schema);
        $columns = array_column(self::columns($db, 'sites'), 'name');
        foreach (self::SITE_ADDITIONS as $name => $definition) {
            if (!in_array($name, $columns, true)) {
                $db->exec("ALTER TABLE sites ADD COLUMN $name $definition");
            }
        }
        self::validateSchema($db, true);
    }

    private static function migrateToVersionTwo(PDO $db): void
    {
        self::validateSchema($db, true);
        $db->exec('ALTER TABLE sites ADD COLUMN health_error_code TEXT');
        $db->exec('ALTER TABLE sites ADD COLUMN health_http_status INTEGER CHECK (health_http_status BETWEEN 100 AND 599)');
    }

    private static function migrateToVersionThree(PDO $db): void
    {
        self::validateSchema($db, true);
        $db->exec("CREATE TABLE github_cooldowns (
            scope TEXT NOT NULL,
            resource TEXT NOT NULL CHECK (resource IN ('core', 'search', 'secondary')),
            eligible_at INTEGER NOT NULL CHECK (typeof(eligible_at) = 'integer' AND eligible_at >= 0),
            PRIMARY KEY (scope, resource)
        )");
    }

    private static function migrateToVersionFour(PDO $db): void
    {
        $db->exec('ALTER TABLE sites ADD COLUMN config_revision INTEGER NOT NULL DEFAULT 0 CHECK (typeof(config_revision) = \'integer\' AND config_revision >= 0)');
        $db->exec("CREATE TABLE installation_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            check_interval_minutes INTEGER NOT NULL DEFAULT 10 CHECK (typeof(check_interval_minutes) = 'integer' AND check_interval_minutes > 0)
        )");
        $db->exec('INSERT INTO installation_settings (id) VALUES (1)');
        $db->exec("CREATE TABLE worker_runtime (
            id INTEGER PRIMARY KEY CHECK (id = 1), run_id TEXT,
            stop_requested INTEGER NOT NULL DEFAULT 0 CHECK (stop_requested IN (0, 1)),
            fairness_turn INTEGER NOT NULL DEFAULT 0 CHECK (typeof(fairness_turn) = 'integer' AND fairness_turn >= 0)
        )");
        $db->exec('INSERT INTO worker_runtime (id) VALUES (1)');
        $db->exec("CREATE TABLE worker_progress (
            site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
            config_revision INTEGER NOT NULL CHECK (config_revision >= 0),
            latest_release INTEGER NOT NULL DEFAULT 0 CHECK (latest_release >= 0),
            latest_commit INTEGER NOT NULL DEFAULT 0 CHECK (latest_commit >= 0),
            open_issues INTEGER NOT NULL DEFAULT 0 CHECK (open_issues >= 0),
            open_prs INTEGER NOT NULL DEFAULT 0 CHECK (open_prs >= 0)
        )");
    }

    private static function migrateToVersionFive(PDO $db): void
    {
        $db->exec("CREATE TABLE check_history (
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
        )");
        $db->exec('CREATE INDEX check_history_site_time ON check_history(site_id, checked_at, id)');
        $db->exec('CREATE INDEX check_history_time ON check_history(checked_at, id)');
    }

    private static function validateSchema(PDO $db, bool $complete): void
    {
        foreach (self::BASE_COLUMNS as $table => $required) {
            $statement = $db->prepare('SELECT type FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE');
            $statement->execute([$table]);
            $type = $statement->fetchColumn();
            $statement->closeCursor();
            if ($type === false && !$complete) { continue; }
            if ($type !== 'table') {
                throw new RuntimeException('Incompatible SQLite schema: expected table ' . $table . '.');
            }
            $columns = self::columns($db, $table);
            if ($complete && $table === 'sites') { $required = array_merge($required, array_keys(self::SITE_ADDITIONS)); }
            $missing = array_diff($required, array_column($columns, 'name'));
            $primary = $table === 'login_limits' ? 'key' : 'id';
            $keys = array_column(array_filter($columns, fn (array $column): bool => (int) $column['pk'] > 0), 'name');
            if ($missing !== [] || $keys !== [$primary]) {
                throw new RuntimeException('Incompatible SQLite schema: invalid columns or primary key in ' . $table . '.');
            }
        }
    }

    private static function columns(PDO $db, string $table): array
    {
        $statement = $db->query('PRAGMA main.table_info(' . $table . ')');
        try { return $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
    }
}
