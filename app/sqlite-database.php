<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    public const CURRENT_SCHEMA_VERSION = 2;

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

    public static function connect(#[\SensitiveParameter] ?string $path = null): PDO
    {
        $path = self::resolvePath($path);
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

    private static function resolvePath(#[\SensitiveParameter] ?string $path): string
    {
        return $path ?? (getenv('TABLO_DB') ?: dirname(__DIR__) . '/storage/tablo.sqlite');
    }

    public static function openExisting(#[\SensitiveParameter] ?string $path = null): PDO
    {
        $path = self::resolvePath($path);
        if ($path === '' || $path === ':memory:' || str_contains($path, "\0")) {
            throw new InstallationException('Installation unavailable.');
        }
        self::validateExistingUri($path);
        try {
            // URI options cannot grant CREATE when the actual open flags omit it.
            $flags = PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS;
            $readWrite = PHP_VERSION_ID >= 80400 ? \Pdo\Sqlite::OPEN_READWRITE : PDO::SQLITE_OPEN_READWRITE;
            $db = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                $flags => $readWrite,
            ]);
            $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            self::installationIdentity($db);
            self::existingAdminHash($db);
            return $db;
        } catch (RuntimeException $error) {
            // DSNs and native diagnostics may contain private configuration.
            self::refuseExistingFailure($error, 'Installation unavailable.');
        }
    }

    private static function refuseExistingFailure(#[\SensitiveParameter] RuntimeException $error, string $message): never
    {
        $code = $error instanceof \PDOException ? (($error->errorInfo[1] ?? 0) & 255) : 0;
        if (in_array($code, [5, 6], true)) {
            $safe = new \PDOException('Installation busy.');
            $safe->errorInfo = ['HY000', $code, null];
            throw $safe;
        }
        throw new InstallationException($message);
    }

    private static function validateExistingUri(#[\SensitiveParameter] string $path): void
    {
        if (!str_starts_with($path, 'file:')) { return; }
        $uri = explode('#', $path, 2)[0];
        if (str_contains(rawurldecode($uri), "\0")) {
            throw new InstallationException('Installation unavailable.');
        }
        $query = explode('?', $uri, 2)[1] ?? '';
        foreach (explode('&', $query) as $parameter) {
            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            $name = rawurldecode($name);
            $value = rawurldecode($value);
            // SQLite decodes URI keys/values, and these options override useful
            // READWRITE/locking behavior. Keep the original URI for actual PDO.
            if (str_contains($name . $value, "\0")
                || ($name === 'mode' && in_array($value, ['ro', 'memory'], true))
                || (in_array($name, ['immutable', 'nolock'], true)
                    && !in_array(strtolower($value), ['', '0', 'false', 'off', 'no'], true))) {
                throw new InstallationException('Installation unavailable.');
            }
        }
    }

    /** Identity comes from the opened connection, never from its requested DSN. */
    public static function installationIdentity(PDO $db): array
    {
        $statement = $db->query('PRAGMA database_list');
        try { $databases = $statement->fetchAll(); }
        finally { $statement->closeCursor(); }
        foreach ($databases as $database) {
            if ($database['name'] !== 'main') { continue; }
            $file = $database['file'];
            $canonical = is_string($file) && $file !== '' ? realpath($file) : false;
            if ($canonical !== false && is_file($canonical) && is_writable($canonical)) {
                return ['file' => $canonical, 'admin' => 1, 'schema' => self::schemaVersion($db)];
            }
        }
        throw new InstallationException('Installation unavailable.');
    }

    public static function existingAdminHash(PDO $db): string
    {
        try {
            $version = self::schemaVersion($db);
            self::validateSchema($db, true);
        } catch (RuntimeException $error) {
            self::refuseExistingFailure($error, 'Installation schema incompatible.');
        }
        if ($version !== self::CURRENT_SCHEMA_VERSION) {
            throw new InstallationException('Installation schema incompatible.');
        }
        if (array_diff(['health_error_code', 'health_http_status'], array_column(self::columns($db, 'sites'), 'name')) !== []) {
            throw new InstallationException('Installation schema incompatible.');
        }
        $statement = $db->query("SELECT sql FROM main.sqlite_schema WHERE type = 'table' AND name = 'users'");
        try { $sql = $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        // Accept the actual frozen users definition, including its singleton constraint.
        // An advertised user_version or a CHECK hidden in a comment is insufficient.
        $expected = "CREATE TABLE users (id INTEGER PRIMARY KEY CHECK (id = 1), password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')))";
        $normalize = static fn (string $value): string => preg_replace('/\s+/', '', $value);
        if (!is_string($sql) || $normalize($sql) !== $normalize($expected)) {
            throw new InstallationException('Installation schema incompatible.');
        }
        $statement = $db->query('SELECT id, password_hash FROM main.users');
        try { $users = $statement->fetchAll(); }
        finally { $statement->closeCursor(); }
        $hash = $users[0]['password_hash'] ?? null;
        if (count($users) !== 1 || (int) $users[0]['id'] !== 1 || !is_string($hash)
            || password_get_info($hash)['algo'] === null
            || (str_starts_with($hash, '$2') && preg_match('/^\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D', $hash) !== 1)) {
            throw new InstallationException('Administrator unavailable.');
        }
        return $hash;
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
                    1 => self::migrateToVersionTwo($db),
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

    private static function migrateToVersionTwo(PDO $db): void
    {
        self::validateSchema($db, true);
        $db->exec('ALTER TABLE sites ADD COLUMN health_error_code TEXT');
        $db->exec('ALTER TABLE sites ADD COLUMN health_http_status INTEGER CHECK (health_http_status BETWEEN 100 AND 599)');
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
