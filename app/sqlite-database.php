<?php
declare(strict_types=1);

namespace Tablo;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    public const CURRENT_SCHEMA_VERSION = 4;

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

    public static function connect(#[\SensitiveParameter] ?string $path = null, #[\SensitiveParameter] ?TokenVault $vault = null): PDO
    {
        $vault ??= TokenVault::configured(); // Explicit file failure precedes any database open.
        $vault?->assertAvailable(false);
        $path ??= getenv('TABLO_DB') ?: dirname(__DIR__) . '/storage/tablo.sqlite';
        if ($vault?->isExternal()) {
            self::validateExistingUri($path); // Preserve the reviewed literal URI/VFS admission.
        }
        // SQLite file: URIs are not filesystem paths for PHP's directory functions.
        if ($path !== ':memory:' && !str_starts_with($path, 'file:') && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($vault?->isExternal()) {
            self::authenticateExternalKey($db, $vault);
        }
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

    private static function authenticateExternalKey(PDO $db, #[\SensitiveParameter] TokenVault $vault): void
    {
        $present = false;
        $verified = false;
        foreach (['sites' => 'github_token', 'git_tokens' => 'encrypted_token'] as $table => $column) {
            $statement = $db->prepare("SELECT type FROM main.sqlite_schema WHERE name = ?");
            try { $statement->execute([$table]); $type = $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
            if ($type !== 'table' || !in_array($column, array_column(self::columns($db, $table), 'name'), true)) { continue; }
            $statement = $db->query('SELECT ' . $column . ' FROM main.' . $table . ' WHERE ' . $column . " IS NOT NULL AND " . $column . " <> ''");
            try {
                while (($encrypted = $statement->fetchColumn()) !== false) {
                    $present = true;
                    try { $plaintext = $vault->decrypt($encrypted); $verified = true; unset($plaintext); }
                    catch (SharedKeyFailure $error) { throw $error; }
                    catch (RuntimeException) { /* An individual damaged credential is not a global failure. */ }
                    finally { unset($encrypted); }
                }
            } finally { $statement->closeCursor(); }
        }
        if ($present && !$verified) {
            throw new SharedKeyFailure(SharedKeyFailure::UNVERIFIED_DIAGNOSTIC, SharedKeyFailure::UNVERIFIED);
        }
    }

    public static function preflightExternalKey(#[\SensitiveParameter] ?string $path = null): void
    {
        $vault = TokenVault::configured();
        if ($vault === null) { throw new SharedKeyFailure('External token key configuration is required for this preflight.'); }
        $db = self::openExistingConnection($path);
        self::authenticateExternalKey($db, $vault);
        self::schemaVersion($db); // Refuse a future schema; never migrate or configure WAL.
    }

    private static function resolvePath(#[\SensitiveParameter] ?string $path): string
    {
        return $path ?? (getenv('TABLO_DB') ?: dirname(__DIR__) . '/storage/tablo.sqlite');
    }

    public static function openExisting(#[\SensitiveParameter] ?string $path = null): PDO
    {
        $db = self::openExistingConnection($path);
        try { self::installationIdentity($db); self::existingAdminHash($db); }
        catch (RuntimeException $error) { self::refuseExistingFailure($error, 'Installation unavailable.'); }
        return $db;
    }

    public static function openWorkerControl(#[\SensitiveParameter] ?string $path = null): PDO
    {
        $db = self::openExistingConnection($path);
        self::installationIdentity($db);
        if (self::schemaVersion($db) !== self::CURRENT_SCHEMA_VERSION
            || array_diff(['id', 'run_id', 'stop_requested', 'fairness_turn'], array_column(self::columns($db, 'worker_runtime'), 'name')) !== []) {
            throw new InstallationException('Worker control schema incompatible.');
        }
        return $db;
    }

    private static function openExistingConnection(#[\SensitiveParameter] ?string $path): PDO
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
            // READWRITE/locking behavior. Explicit VFS selection is unreviewed;
            // use SQLite's native default. Keep the original URI for actual PDO.
            if (str_contains($name . $value, "\0")
                || $name === 'vfs'
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
        self::validateCurrentAdditions($db);
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

    private static function validateCurrentAdditions(PDO $db): void
    {
        foreach (['sites' => ['config_revision'], 'github_cooldowns' => ['scope', 'resource', 'eligible_at'],
            'installation_settings' => ['id', 'check_interval_minutes'],
            'worker_runtime' => ['id', 'run_id', 'stop_requested', 'fairness_turn'],
            'worker_progress' => ['site_id', 'config_revision', 'latest_release', 'latest_commit', 'open_issues', 'open_prs']] as $table => $required) {
            $statement = $db->prepare("SELECT type FROM main.sqlite_schema WHERE name = ?");
            try { $statement->execute([$table]); $type = $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
            if ($type !== 'table' || array_diff($required, array_column(self::columns($db, $table), 'name')) !== []) {
                throw new InstallationException('Installation schema incompatible.');
            }
        }
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
                    2 => self::migrateToVersionThree($db),
                    3 => self::migrateToVersionFour($db),
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
