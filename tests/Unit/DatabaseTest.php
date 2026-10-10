<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;
use Throwable;

final class DatabaseTest
{
    private static function schema(bool $historic = false): string
    {
        return file_get_contents(dirname(__DIR__, 2) . ($historic ? '/tests/fixtures/pre-json-schema.sql' : '/database/schema.sql'));
    }

    private static function snapshot(PDO $db): array
    {
        $schema = $db->query('SELECT name, type, sql FROM sqlite_schema ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($schema as $object) {
            if ($object['type'] === 'table') {
                $rows[$object['name']] = $db->query('SELECT * FROM "' . $object['name'] . '" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        return ['version' => (int) $db->query('PRAGMA user_version')->fetchColumn(), 'schema' => $schema, 'rows' => $rows];
    }

    private static function errorMessage(callable $action): ?string
    {
        try { $action(); } catch (Throwable $error) { return $error->getMessage(); }
        return null;
    }

    #[Test]
    public function initializesFileAndMemoryWithVersionAndConnectionSettings(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-');
        try {
            foreach ([':memory:', $directory->path . '/nested/test.sqlite'] as $path) {
                $db = Database::connect($path);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
                Assert::same((int) $db->query('PRAGMA foreign_keys')->fetchColumn(), 1);
                Assert::same((int) $db->query('PRAGMA busy_timeout')->fetchColumn(), 5000);
                Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
                Assert::same($db->query('SELECT name FROM sqlite_schema WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name')
                    ->fetchAll(PDO::FETCH_COLUMN), ['check_history', 'git_tokens', 'github_cooldowns', 'incident_checkpoints', 'incidents', 'installation_settings', 'login_limits', 'sites', 'users', 'worker_progress', 'worker_runtime']);
                Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
                Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), $path === ':memory:' ? 'memory' : 'wal');
                unset($db);
            }
            $db = Database::connect($directory->path . '/nested/test.sqlite');
            Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'wal');
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
        } finally {
            unset($db);
            $directory->close();
        }
    }

    #[Test]
    public function rejectsUnsupportedWalWithoutFallbackOrPrivatePathInPolicyError(): void
    {
        // SQLite's unnamed temporary database cannot enter WAL and returns delete.
        Assert::same(self::errorMessage(fn () => Database::connect('')),
            'SQLite WAL is required for file databases; use writable local storage.');
        // An explicit memory URI is also unsupported; only :memory: is the memory policy.
        Assert::same(self::errorMessage(fn () => Database::connect('file::memory:?cache=shared')),
            'SQLite WAL is required for file databases; use writable local storage.');
    }

    #[Test]
    public function rejectedFileSchemasKeepDataVersionAndJournalMetadata(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-rejected-');
        try {
            foreach ([Database::CURRENT_SCHEMA_VERSION + 1, -1, 0] as $version) {
                $path = $directory->path . '/' . $version . '.sqlite';
                $db = new MigrationPdo($path);
                $db->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)');
                $db->exec("INSERT INTO sites VALUES (1, 'preserved')");
                $db->exec('PRAGMA user_version = ' . $version);
                $before = self::snapshot($db);
                unset($db);
                Assert::true(self::errorMessage(fn () => Database::connect($path)) !== null);
                $db = new MigrationPdo($path);
                Assert::same(self::snapshot($db), $before);
                Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'delete');
                unset($db);
            }
        } finally {
            unset($db);
            $directory->close();
        }
    }

    #[Test]
    public function currentSchemaOnlyReadsVersionEvenInsideCallerTransaction(): void
    {
        $db = new MigrationPdo(':memory:');
        Database::migrate($db);
        $before = self::snapshot($db);
        $db->statements = [];
        Database::migrate($db);
        Assert::same($db->statements, ['PRAGMA main.user_version']);
        Assert::same(self::snapshot($db), $before);
        $db->beginTransaction();
        $db->statements = [];
        Database::migrate($db);
        Assert::same($db->statements, ['PRAGMA main.user_version']);
        Assert::true($db->inTransaction());
        $db->rollBack();
    }

    #[Test]
    public function importsHistoricalAndMixedUnversionedSchemasWithoutChangingSecrets(): void
    {
        $historic = self::schema(true); // Exact schema from d964304, before JSON settings.
        $withoutSaved = preg_replace('/CREATE TABLE IF NOT EXISTS git_tokens \(.*?\);\s*/s', '', $historic);
        $withoutSaved = preg_replace('/^\s*git_token_id INTEGER[^\r\n]*\R/m', '', $withoutSaved);
        $schemas = [$historic, $withoutSaved, preg_replace('/^\s*github_token TEXT,\R/m', '', $historic),
            preg_replace('/^\s*github_token TEXT,\R/m', '', $withoutSaved), self::schema()];
        foreach ($schemas as $schema) {
            $directory = new TemporaryDirectory('tablo-schema-upgrade-');
            try {
                $path = $directory->path . '/test.sqlite';
                $old = new MigrationPdo($path);
                $old->exec($schema);
                $hash = password_hash('synthetic-admin-password', PASSWORD_BCRYPT, ['cost' => 4]);
                $old->prepare('INSERT INTO users (id, password_hash) VALUES (1, ?)')->execute([$hash]);
                $old->exec("INSERT INTO login_limits VALUES ('synthetic-address', 2, 123)");
                $vault = new TokenVault($directory->path . '/key');
                $cipher = $vault->encrypt('synthetic-individual-token');
                $fields = ['name' => 'Existing', 'url' => 'https://example.com', 'repository' => 'example/project',
                    'branch' => 'feature/demo', 'health_path' => '/up/', 'version_path' => '/version',
                    'online' => 1, 'deployed_version' => '1.2.3', 'checked_at' => '2026-01-01T00:00:00Z'];
                $individual = str_contains($schema, 'github_token TEXT');
                $saved = str_contains($schema, 'CREATE TABLE IF NOT EXISTS git_tokens');
                if ($individual) { $fields['github_token'] = $cipher; }
                $old->prepare('INSERT INTO sites (' . implode(',', array_keys($fields)) . ') VALUES ('
                    . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(array_values($fields));
                if ($saved) {
                    $tokens = new GitTokenRepository($old, $vault);
                    $token = $tokens->save(['name' => 'Saved', 'provider' => 'github', 'token' => 'synthetic-shared-token']);
                    $old->prepare("INSERT INTO sites (name,url,repository,git_token_id) VALUES ('Shared','https://example.com','example/shared',?)")
                        ->execute([$token]);
                    unset($tokens);
                }
                $before = self::snapshot($old);
                $keyHash = hash_file('sha256', $directory->path . '/key');
                unset($old);
                $db = Database::connect($path);
                Database::migrate($db);
                $after = self::snapshot($db);
                Assert::same($after['version'], Database::CURRENT_SCHEMA_VERSION);
                foreach ($before['rows'] as $table => $rows) {
                    foreach ($rows as $index => $row) {
                        Assert::same(array_intersect_key($after['rows'][$table][$index], $row), $row, 'Changed existing ' . $table . ' row');
                    }
                }
                Assert::true(password_verify('synthetic-admin-password', $db->query('SELECT password_hash FROM users')->fetchColumn()));
                Assert::same(hash_file('sha256', $directory->path . '/key'), $keyHash);
                $sites = new SiteRepository($db, $vault);
                $site = $sites->find(1);
                Assert::same($site['health_check_mode'], 'http');
                Assert::same($site['health_json_operator'], '==');
                foreach (['version_json_path', 'health_json_path', 'health_json_expected_value'] as $column) {
                    Assert::same($site[$column], '');
                }
                Assert::same($sites->tokenFor($site), $individual ? 'synthetic-individual-token' : '');
                if ($saved) { Assert::same($sites->tokenFor($sites->find(2)), 'synthetic-shared-token'); }
                Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
                unset($db, $sites, $vault);
            } finally {
                unset($db, $old, $sites, $tokens, $vault);
                $directory->close();
            }
        }
    }

    #[Test]
    public function upgradesVersionOneDiagnosticsAtomicallyWithoutChangingExistingRows(): void
    {
        foreach ([null, 'ALTER TABLE sites ADD COLUMN health_http_status', 'PRAGMA main.user_version = 2', 'COMMIT'] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(self::schema());
            $db->exec("PRAGMA user_version = 1;
                INSERT INTO users (id,password_hash) VALUES (1,'synthetic-hash');
                INSERT INTO sites (name,url,repository,github_token,health_path,online,last_error)
                VALUES ('Existing','https://example.com/app','example/project','synthetic-ciphertext','/up',0,'Health: HTTP 503')");
            $before = self::snapshot($db);
            $db->failBefore = $failure;
            if ($failure !== null) {
                Assert::true(str_contains(self::errorMessage(fn () => Database::migrate($db)) ?? '', 'injected_migration_failure'));
                Assert::same(self::snapshot($db), $before, 'failed version 2 migration leaves version 1 intact');
                $db->failBefore = null;
            }
            Database::migrate($db);
            $after = self::snapshot($db);
            Assert::same($after['version'], Database::CURRENT_SCHEMA_VERSION);
            foreach ($before['rows'] as $table => $rows) {
                foreach ($rows as $index => $row) {
                    Assert::same(array_intersect_key($after['rows'][$table][$index], $row), $row);
                }
            }
            Assert::same($after['rows']['sites'][0]['health_error_code'], null);
            Assert::same($after['rows']['sites'][0]['health_http_status'], null);
            $db->statements = [];
            Database::migrate($db);
            Assert::same($db->statements, ['PRAGMA main.user_version']);
        }
    }

    #[Test]
    public function rollsBackBootstrapAlterVersionAndCommitFailuresAndAllowsRetry(): void
    {
        foreach (['bootstrap', 'alter', 'version', 'commit'] as $failure) {
            $directory = new TemporaryDirectory('tablo-schema-rollback-');
            try {
                $path = $directory->path . '/test.sqlite';
                $db = new MigrationPdo($path);
                if ($failure !== 'bootstrap') {
                    $db->exec(self::schema(true));
                    $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'synthetic-preserved-hash');
                        INSERT INTO sites (name,url,repository,github_token) VALUES ('Existing','https://example.com','example/project','synthetic-ciphertext')");
                }
                $before = self::snapshot($db);
                $db->failAfterFirstCreate = $failure === 'bootstrap';
                $db->failAfter = match ($failure) {
                    'alter' => 'ALTER TABLE', 'version' => 'PRAGMA main.user_version =', default => null,
                };
                $db->failBefore = $failure === 'commit' ? 'COMMIT' : null;
                $message = self::errorMessage(fn () => Database::migrate($db));
                Assert::true(str_contains($message ?? '', 'injected_migration_failure'), $failure . ' did not fail');
                Assert::false($db->inTransaction());
                Assert::same(self::snapshot($db), $before, $failure . ' left partial data/schema/version');
                unset($db);
                $db = new MigrationPdo($path);
                Assert::same(self::snapshot($db), $before, $failure . ' persisted partial changes');
                Database::migrate($db);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
                Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
            } finally {
                unset($db);
                $directory->close();
            }
        }
    }

    #[Test]
    public function rejectsFutureOrNegativeVersionsWithoutWrites(): void
    {
        foreach ([Database::CURRENT_SCHEMA_VERSION + 1, -1] as $version) {
            $db = new MigrationPdo(':memory:');
            $db->exec(self::schema());
            $db->exec('PRAGMA user_version = ' . $version);
            $before = self::snapshot($db);
            $db->statements = [];
            $message = self::errorMessage(fn () => Database::migrate($db));
            Assert::true(str_contains($message ?? '', 'Unsupported SQLite schema version ' . $version));
            Assert::same($db->statements, ['PRAGMA main.user_version']);
            Assert::same(self::snapshot($db), $before);
        }
    }

    #[Test]
    public function refusesIncompatibleTablesAndViewsBeforeBootstrap(): void
    {
        foreach (["CREATE VIEW sites AS SELECT 1 AS id", 'CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE users (id INTEGER, password_hash TEXT, created_at TEXT)',
            'CREATE VIEW git_tokens AS SELECT 1 AS id',
            'CREATE TABLE login_limits (key TEXT, failures INTEGER, window_start INTEGER)'] as $sql) {
            $db = new MigrationPdo(':memory:');
            $db->exec($sql);
            $before = self::snapshot($db);
            Assert::true(str_contains(self::errorMessage(fn () => Database::migrate($db)) ?? '', 'Incompatible SQLite schema'));
            Assert::same(self::snapshot($db), $before);
            Assert::false($db->inTransaction());
        }
    }

    #[Test]
    public function leavesCallerTransactionAndAdditionalTablesUntouched(): void
    {
        $db = new MigrationPdo(':memory:');
        $db->beginTransaction();
        $db->exec('CREATE TABLE notes (value TEXT)');
        $db->exec("INSERT INTO notes VALUES ('caller-owned')");
        $db->statements = [];
        Assert::true(str_contains(self::errorMessage(fn () => Database::migrate($db)) ?? '', 'existing transaction'));
        Assert::same($db->statements, ['PRAGMA main.user_version']);
        Assert::true($db->inTransaction());
        Assert::same($db->query('SELECT value FROM notes')->fetchColumn(), 'caller-owned');
        $db->commit();
        Database::migrate($db);
        Assert::same($db->query('SELECT value FROM notes')->fetchColumn(), 'caller-owned');
        Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
    }

    #[Test]
    public function doesNotEndManuallyStartedCallerTransaction(): void
    {
        $db = new MigrationPdo(':memory:');
        $db->exec('CREATE TABLE notes (value TEXT)');
        $db->exec('BEGIN IMMEDIATE');
        $db->exec("INSERT INTO notes VALUES ('pending')");
        $db->statements = [];
        $message = self::errorMessage(fn () => Database::migrate($db));
        Assert::true(str_contains($message ?? '', 'existing transaction') || str_contains($message ?? '', 'within a transaction'));
        Assert::false(in_array('COMMIT', $db->statements, true));
        Assert::false(in_array('ROLLBACK', $db->statements, true));
        Assert::same($db->query('SELECT value FROM notes')->fetchColumn(), 'pending');
        $db->exec('ROLLBACK');
        Assert::same((int) $db->query('SELECT count(*) FROM notes')->fetchColumn(), 0);
        Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
    }

    #[Test]
    public function rollbackFailureDoesNotReplaceMigrationError(): void
    {
        $db = new MigrationPdo(':memory:');
        $db->failAfterFirstCreate = $db->failRollback = true;
        $message = self::errorMessage(fn () => Database::migrate($db));
        Assert::true(str_contains($message ?? '', 'injected_migration_failure'));
        Assert::false(str_contains($message ?? '', 'Injected rollback failure'));
        $db->failRollback = false;
        $db->exec('ROLLBACK');
        Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
        Assert::same($db->query('SELECT name FROM sqlite_schema')->fetchAll(), []);
    }
}
