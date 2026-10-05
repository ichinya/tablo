<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    public const CURRENT_SCHEMA_VERSION = 1;

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
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        self::migrate($db);
        if ($path !== ':memory:') {
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
                match ($version) {
                    0 => self::migrateToVersionOne($db),
                    default => throw new RuntimeException('No migration for SQLite schema version ' . $version),
                };
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
