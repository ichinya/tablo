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

final class DatabaseWalNegotiationTest
{
    private static function negotiate(PDO $db, string $owner = Database::class): ?\Throwable
    {
        try { (new \ReflectionMethod($owner, 'ensureWal'))->invoke(null, $db); }
        catch (\Throwable $error) { return $error; }
        return null;
    }

    #[Test]
    public function walRetriesOnlyGenuineBusyCodesAndReleasesEachStatement(): void
    {
        foreach ([5, 261, 773] as $code) {
            foreach (['prepare', 'execute', 'fetch'] as $stage) {
                $db = new DatabaseWalPdo();
                $db->plan['failure'] = DatabaseWalPdo::error($code);
                $db->plan['failureStage'] = $stage;
                Assert::same(self::negotiate($db), null);
                Assert::same($db->timeout, 5000);
                Assert::same($db->setters, 2);
                Assert::true($db->allReleased());
                Assert::same($db->timeouts, [0, 5000]);
            }
        }
        foreach ([null, '5', 6, 8, 10, 17, 517, 1029] as $code) {
            $db = new DatabaseWalPdo();
            $error = DatabaseWalPdo::error($code);
            $db->plan['failure'] = $error;
            Assert::same(self::negotiate($db), $error);
            Assert::same($db->setters, 1);
            Assert::same($db->timeout, 5000);
            Assert::true($db->allReleased());
        }
    }

    #[Test]
    public function walCleanupAndRestoreFailuresNeverRetryOrMaskPrimaryFailure(): void
    {
        foreach ([true, false] as $throwCleanup) {
            $db = new DatabaseWalPdo();
            $primary = DatabaseWalPdo::error(5);
            $db->plan['failure'] = $primary;
            $db->plan['cleanupFailure'] = $throwCleanup ? new \RuntimeException('Synthetic cleanup failure') : false;
            $db->plan['restoreFailure'] = new \RuntimeException('Synthetic restore failure');
            Assert::same(self::negotiate($db), $primary);
            Assert::same($db->setters, 1);
            Assert::same($db->timeouts, [0, 5000]);
            Assert::true($db->allReleased());
        }
        $db = new DatabaseWalPdo();
        $db->plan['restoreFailure'] = new \RuntimeException('Synthetic restore failure');
        Assert::same(self::negotiate($db), $db->plan['restoreFailure']);
        $db = new DatabaseWalPdo();
        $db->plan['cleanupFailure'] = new \RuntimeException('Synthetic cleanup failure');
        Assert::same(self::negotiate($db), $db->plan['cleanupFailure']);
        Assert::same($db->setters, 0);
        Assert::same($db->timeout, 5000);
        $db = new DatabaseWalPdo();
        $db->plan['initialFailure'] = DatabaseWalPdo::error(5);
        Assert::same(self::negotiate($db), $db->plan['initialFailure']);
        Assert::same($db->setters, 0);
        Assert::same($db->timeouts, [0, 5000]);
    }

    #[Test]
    public function walReadsActualModeAndImmediatelyRefusesUnsupportedMode(): void
    {
        $db = new DatabaseWalPdo();
        $db->plan['mode'] = 'wal';
        Assert::same(self::negotiate($db), null);
        Assert::same($db->setters, 0);
        Assert::same($db->sql, ['PRAGMA main.journal_mode']);
        $db = new DatabaseWalPdo();
        $db->plan['returnedMode'] = 'delete';
        Assert::same(self::negotiate($db)?->getMessage(), 'SQLite WAL is required for file databases; use writable local storage.');
        Assert::same($db->setters, 1);
        Assert::same($db->timeout, 5000);
        Assert::true($db->allReleased());
    }

    #[Test]
    public function walDeadlineClipsBackoffAndChecksAgainAfterSchedulerWake(): void
    {
        $owner = 'Tablo\\Tests\\Support\\WalClock\\Database';
        if (!class_exists($owner, false)) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/sqlite-database.php');
            if (substr_count($source, 'namespace Tablo;') !== 1) { throw new \RuntimeException('Clock copy namespace changed'); }
            $directory = new TemporaryDirectory('tablo-wal-clock-');
            try {
                $copy = str_replace('namespace Tablo;', 'namespace Tablo\\Tests\\Support\\WalClock;', $source);
                file_put_contents($directory->path . '/owner.php', $copy);
                require $directory->path . '/owner.php';
            } finally { $directory->close(); }
        }
        foreach ([0, 30_000_000] as $overshoot) {
            \Tablo\Tests\Support\WalClock\Clock::reset($overshoot);
            $db = new DatabaseWalPdo();
            $db->plan['failure'] = DatabaseWalPdo::error(5);
            $db->plan['repeatFailure'] = true;
            Assert::same(self::negotiate($db, $owner), $db->plan['failure']);
            Assert::same($db->timeout, 5000);
            $sleeps = \Tablo\Tests\Support\WalClock\Clock::$sleeps;
            Assert::same(array_slice(array_column($sleeps, 'microseconds'), 0, 3), [5000, 10000, 20000]);
            foreach ($sleeps as $sleep) {
                Assert::true($sleep['microseconds'] <= 25000);
                Assert::true($sleep['before'] + $sleep['microseconds'] * 1000 <= 5_000_000_000);
            }
            foreach ($db->attemptTimes as $time) { Assert::true($time < 5_000_000_000); }
            Assert::true($db->allReleased());
        }
    }

}

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
                    ->fetchAll(PDO::FETCH_COLUMN), ['git_tokens', 'github_cooldowns', 'installation_settings', 'login_limits', 'sites', 'users', 'worker_progress', 'worker_runtime']);
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

/** Focused statement double; real process tests prove actual connect contention. */
final class DatabaseWalPdo extends PDO
{
    public int $timeout = 5000;
    public array $timeouts = [];
    public array $sql = [];
    public array $statements = [];
    public array $attemptTimes = [];
    public int $setters = 0;
    public array $plan = ['mode' => 'delete', 'returnedMode' => 'wal', 'failure' => null, 'failureStage' => 'execute',
        'repeatFailure' => false, 'cleanupFailure' => null, 'restoreFailure' => null, 'initialFailure' => null];

    public function __construct() {}

    public static function error(mixed $code): \PDOException
    {
        $error = new \PDOException('Synthetic HY000 database is locked', 5);
        $error->errorInfo = ['HY000', $code, null];
        return $error;
    }

    public function exec(string $statement): int|false
    {
        $this->timeout = $statement === 'PRAGMA busy_timeout = 0' ? 0 : 5000;
        $this->timeouts[] = $this->timeout;
        if ($this->timeout === 0 && $this->plan['initialFailure'] !== null) { throw $this->plan['initialFailure']; }
        if ($this->timeout === 5000 && $this->plan['restoreFailure'] !== null) { throw $this->plan['restoreFailure']; }
        return 0;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        Assert::same($this->timeout, 0);
        Assert::true($this->allReleased(), 'Statement remained strongly reachable before next prepare');
        $this->sql[] = $query;
        $this->attemptTimes[] = \Tablo\Tests\Support\WalClock\Clock::$now;
        $setter = $query === 'PRAGMA main.journal_mode = WAL';
        if ($setter) { $this->setters++; }
        $failure = $setter && ($this->setters === 1 || $this->plan['repeatFailure']) ? $this->plan['failure'] : null;
        if ($failure !== null && $this->plan['failureStage'] === 'prepare') { throw $failure; }
        $statement = new DatabaseWalStatement($this, $setter, $failure);
        $this->statements[] = \WeakReference::create($statement);
        return $statement;
    }

    public function allReleased(): bool
    {
        foreach ($this->statements as $reference) { if ($reference->get() !== null) { return false; } }
        return true;
    }
}

final class DatabaseWalStatement extends \PDOStatement
{
    public function __construct(private readonly DatabaseWalPdo $db, private readonly bool $setter, private readonly ?\PDOException $failure) {}

    public function execute(?array $params = null): bool
    {
        if ($this->failure !== null && $this->db->plan['failureStage'] === 'execute') { throw $this->failure; }
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->failure !== null && $this->db->plan['failureStage'] === 'fetch') { throw $this->failure; }
        return $this->setter ? $this->db->plan['returnedMode'] : $this->db->plan['mode'];
    }

    public function closeCursor(): bool
    {
        // In primary-error controls, fail only on the failing setter's cleanup.
        if ($this->db->plan['cleanupFailure'] !== null && ($this->db->plan['failure'] === null || $this->failure !== null)) {
            if ($this->db->plan['cleanupFailure'] === false) { return false; }
            throw $this->db->plan['cleanupFailure'];
        }
        return true;
    }
}
