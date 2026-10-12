<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class BaseSchema
{
    private const TEXT_COLUMN_TYPE = 'TEXT';
    private const SITE_ADDITIONS = [
        'github_token' => self::TEXT_COLUMN_TYPE,
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

    public static function migrateToVersionOne(PDO $db): void
    {
        BaseSchema::validateSchema($db, SchemaCompleteness::Partial);
        // schema.sql is the frozen version 1 bootstrap; future versions need separate transitions.
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($schema === false) { throw new RuntimeException('Cannot read SQLite bootstrap schema.'); }
        $db->exec($schema);
        $columns = array_column(BaseSchema::columns($db, 'sites'), 'name');
        foreach (self::SITE_ADDITIONS as $name => $definition) {
            if (!(!in_array($name, $columns, strict: true))) { continue; }

                $db->exec("ALTER TABLE sites ADD COLUMN {$name} {$definition}");

        }
        BaseSchema::validateSchema($db, SchemaCompleteness::Complete);
    }
    public static function migrateToVersionTwo(PDO $db): void
    {
        BaseSchema::validateSchema($db, SchemaCompleteness::Complete);
        $db->exec('ALTER TABLE sites ADD COLUMN health_error_code TEXT');
        $db->exec('ALTER TABLE sites ADD COLUMN health_http_status INTEGER CHECK (health_http_status BETWEEN 100 AND 599)');
    }
    public static function migrateToVersionThree(PDO $db): void
    {
        BaseSchema::validateSchema($db, SchemaCompleteness::Complete);
        $db->exec("CREATE TABLE github_cooldowns (
            scope TEXT NOT NULL,
            resource TEXT NOT NULL CHECK (resource IN ('core', 'search', 'secondary')),
            eligible_at INTEGER NOT NULL CHECK (typeof(eligible_at) = 'integer' AND eligible_at >= 0),
            PRIMARY KEY (scope, resource)
        )");
    }
    public static function migrateToVersionFour(PDO $db): void
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
    public static function validateSchema(PDO $db, SchemaCompleteness $completeness): void
    {
        $complete = $completeness === SchemaCompleteness::Complete;
        foreach (self::BASE_COLUMNS as $table => $required) {
            $statement = $db->prepare('SELECT type FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE');
            $statement->execute([$table]);
            $type = $statement->fetchColumn();
            $statement->closeCursor();
            if ($type === false && !$complete) { continue; }
            if ($type !== 'table') {
                throw new RuntimeException('Incompatible SQLite schema: expected table ' . $table . '.');
            }
            $columns = BaseSchema::columns($db, $table);
            if ($complete && $table === 'sites') { $required = array_merge($required, array_keys(self::SITE_ADDITIONS)); }
            $missing = array_diff($required, array_column($columns, 'name'));
            $primary = $table === 'login_limits' ? 'key' : 'id';
            $keys = array_column(array_filter($columns, static fn (array $column): bool => (int) $column['pk'] > 0), 'name');
            if ($missing !== [] || $keys !== [$primary]) {
                throw new RuntimeException('Incompatible SQLite schema: invalid columns or primary key in ' . $table . '.');
            }
        }
    }
    public static function columns(PDO $db, string $table): array
    {
        $statement = $db->query('PRAGMA main.table_info(' . $table . ')');
        try { return $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
    }

}
