<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

final class EngineTransferRefusalTest
{
    #[Test]
    public function flagValuesRefuseBeforeKeyDatabaseOrTransferFilesAreOpened(): void
    { engine_transfer_cli_flag_values(); }

    #[Test]
    public function blobStoredInTextPrimaryKeyRefusesWithoutNormalizationOrRepeatedCursor(): void
    {
        $fixture = new EngineFixture('transfer-storage-refusal');
        $root = EngineSchedule::directory() . '/cases/' . $fixture->case;
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        $source->exec('UPDATE login_limits SET `key` = CAST(`key` AS BLOB)');
        $before = \Tablo\ServerSchema::rows($source, 'SELECT CAST(`key` AS BLOB) AS key_bytes, typeof(`key`) AS storage_type FROM login_limits');
        Assert::same($before[0]['storage_type'] ?? null, 'blob'); $category = null;
        try { DatabaseTransfer::preflight($source, $fixture->vault, $root . '/scratch.sqlite', offline: true); } catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema');
        Assert::same(\Tablo\ServerSchema::rows($source, 'SELECT CAST(`key` AS BLOB) AS key_bytes, typeof(`key`) AS storage_type FROM login_limits'), $before);
        Assert::false(file_exists($root . '/archive')); $fixture->close();
    }

    #[Test]
    public function noncanonicalNumericObjectRowRefusesBeforeTargetStateOrRowsChange(): void
    {
        $fixture = new EngineFixture('transfer-canonical-refusal');
        $root = EngineSchedule::directory() . '/cases/transfer-canonical-refusal';
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
        self::numericObjectArchive($root . '/archive');
        $target = $fixture->maintenance();
        $before = \Tablo\ServerSchema::rows($target, 'SELECT * FROM tablo_database_state');
        $category = null;
        try { DatabaseTransfer::importServer($target, $fixture->vault, $root . '/archive', $root . '/preflight.sqlite', offline: true); }
        catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema');
        Assert::same(\Tablo\ServerSchema::rows($target, 'SELECT * FROM tablo_database_state'), $before);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users', $target), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM installation_settings', $target), 1);
        Assert::same((new \Tablo\ServerMigration($target, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        $fixture->close();
    }

    #[Test]
    public function inflightWorkerRefusesWithoutClearingGenerationOrOpeningArchive(): void
    {
        $fixture = new EngineFixture('transfer-worker-refusal');
        $root = EngineSchedule::directory() . '/cases/transfer-worker-refusal';
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        $source->exec("UPDATE worker_runtime SET run_id = 'owned-unresolved-generation'");
        $category = null;
        try { DatabaseTransfer::preflight($source, $fixture->vault, $root . '/scratch.sqlite', offline: true); }
        catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'busy');
        Assert::same(\Tablo\ServerSchema::rows($source, 'SELECT run_id FROM worker_runtime'), [['run_id' => 'owned-unresolved-generation']]);
        Assert::false(file_exists($root . '/archive'));
    }

    #[Test]
    public function historicalNullPrimaryKeyRefusesBeforeAnyTargetMutation(): void
    {
        $fixture = new EngineFixture('transfer-null-refusal');
        $root = EngineSchedule::directory() . '/cases/transfer-null-refusal';
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        $source->exec('INSERT INTO login_limits (`key`,failures,window_start) VALUES (NULL,1,2)');
        $before = \Tablo\ServerSchema::rows($source, 'SELECT * FROM login_limits ORDER BY `key`');
        $category = null;
        try { DatabaseTransfer::preflight($source, $fixture->vault, $root . '/scratch.sqlite', offline: true); }
        catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema');
        Assert::same(\Tablo\ServerSchema::rows($source, 'SELECT * FROM login_limits ORDER BY `key`'), $before);
    }

    #[Test]
    public function oversizeRowsRefuseTheFiniteHelperWithoutTruncation(): void
    {
        $fixture = new EngineFixture('transfer-envelope-refusal');
        $root = EngineSchedule::directory() . '/cases/transfer-envelope-refusal';
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        \Tablo\ServerSchema::rows($source, 'UPDATE sites SET last_error = ?', [str_repeat('x', times: 98_305)]);
        $category = null;
        try { DatabaseTransfer::preflight($source, $fixture->vault, $root . '/oversize.sqlite', offline: true); }
        catch (DatabaseFailure $error) { $category = $error->category; }
        Assert::same($category, 'schema');
        Assert::same(\Tablo\DatabaseTransferSequence::scalar($source, 'SELECT length(last_error) AS value FROM sites'), 98_305);
    }

    #[Test]
    public function callerSqliteTransactionsRefuseBeforeTransferAllocatesOrChangesSession(): void
    { engine_transfer_caller_transactions(); }

    /** Self-consistent manifest/chunk hashes must not make an object-shaped typed row canonical. */
    private static function numericObjectArchive(string $directory): void
    {
        $manifestPath = $directory . '/manifest.json'; $chunkPath = $directory . '/users.0.jsonl';
        $record = \Tablo\JsonRecord::decode(rtrim(\Tablo\RequiredFile::bytes(file_get_contents($chunkPath)), characters: "\n"));
        $bytes = \Tablo\JsonEncoding::encode((object) $record) . "\n";
        $envelope = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($manifestPath)));
        $header = \Tablo\JsonRecord::checked($envelope['payload'] ?? null);
        $tables = \Tablo\JsonRecord::checked($header['tables'] ?? null); $table = \Tablo\JsonRecord::checked($tables[0] ?? null);
        Assert::same($table['name'] ?? null, 'users'); Assert::same($table['rows'] ?? null, 1);
        $chunks = \Tablo\JsonRecord::checked($table['chunks'] ?? null); $chunk = \Tablo\JsonRecord::checked($chunks[0] ?? null);
        $chunk['bytes'] = strlen($bytes); $chunk['sha256'] = hash('sha256', $bytes); $chunks[0] = $chunk;
        $table['sha256'] = hash('sha256', $bytes); $table['chunks'] = $chunks; $tables[0] = $table; $header['tables'] = $tables;
        $envelope['payload'] = $header; $envelope['sha256'] = hash('sha256', \Tablo\JsonEncoding::encode($header));
        Assert::same(file_put_contents($chunkPath, $bytes), strlen($bytes));
        $manifest = \Tablo\JsonEncoding::encode($envelope);
        Assert::same(file_put_contents($manifestPath, $manifest), strlen($manifest));
    }
}

/** Exercise the public CLI with missing synthetic key/database paths, never inherited credentials. */
function engine_transfer_cli_flag_values(): void
{
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-transfer-cli-options-');
    try {
        $root = $directory->path; $archive = $root . '/archive'; $scratch = $root . '/scratch.sqlite'; $target = $root . '/target.sqlite';
        $variants = [
            ['--export', '--archive', '--offline', '--scratch', $scratch],
            ['--export', '--offline', '--archive', '--scratch'],
            ['--import', '--offline', '--archive', $archive, '--scratch', $scratch, '--sqlite-target', '--archive'],
        ];
        foreach ($variants as $arguments) {
            $result = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/database-transfer.php', ...$arguments],
                $directory, ['TABLO_DB' => $root . '/database.sqlite', 'TABLO_TOKEN_KEY_FILE' => $root . '/absent-key.bin'],
                new \Tablo\Tests\Support\CommandOptions(timeout: 2, cwd: $root));
            Assert::same($result['exit_code'], 1); Assert::same($result['stdout'], '');
            Assert::same($result['stderr'], "Database configuration invalid.\n");
            foreach ([$archive, $scratch, $target, $root . '/absent-key.bin', $root . '/database.sqlite',
                $root . '/--offline', $root . '/--scratch', $root . '/--archive', $root . '/--sqlite-target'] as $path) {
                Assert::false(file_exists($path));
            }
        }
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

/** Both entry points refuse caller ownership before allocating or changing the session. */
function engine_transfer_caller_transactions(): void
{
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-transfer-ownership-');
    $before = getenv('TABLO_TOKEN_KEY_FILE');
    try {
        $key = $directory->path . '/key.bin';
        if (file_put_contents($key, random_bytes(32)) !== 32 || !chmod($key, permissions: 0o600)
            || !putenv('TABLO_TOKEN_KEY_FILE=' . $key)) { throw new \RuntimeException('Synthetic transfer key setup failed.'); }
        $vault = new \Tablo\TokenVault($key, true);
        $variants = [['begin' => 'PDO', 'mode' => \PDO::ERRMODE_EXCEPTION],
            ['begin' => 'BEGIN DEFERRED', 'mode' => \PDO::ERRMODE_EXCEPTION],
            ['begin' => 'SAVEPOINT caller_owned', 'mode' => \PDO::ERRMODE_EXCEPTION],
            ['begin' => 'BEGIN DEFERRED', 'mode' => \PDO::ERRMODE_SILENT]];
        foreach ($variants as $index => $variant) {
            foreach (['export', 'preflight'] as $operation) {
                engine_transfer_caller_transaction_case($directory->path . '/raw-' . $index . '-' . $operation,
                    $vault, $variant['begin'], $variant['mode'], $operation);
            }
        }
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally {
        try {
            $directory->cleanup(static function () use ($before): void {
                if (!putenv(is_string($before) ? 'TABLO_TOKEN_KEY_FILE=' . $before : 'TABLO_TOKEN_KEY_FILE')) {
                    throw new \RuntimeException('Cannot restore transfer key environment.');
                }
            });
        } finally { $directory->cleanup($directory->close(...)); }
    }
}

function engine_transfer_caller_transaction_case(string $prefix, \Tablo\TokenVault $vault, string $begin, int $mode, string $operation): void
{
    $db = null; $observer = null;
    try {
        $db = new \PDO('sqlite:' . $prefix . '.sqlite',
            options: [\PDO::ATTR_ERRMODE => $mode, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE caller_probe (value INTEGER NOT NULL)');
        if ($begin === 'PDO') { $db->beginTransaction(); }
        if ($begin !== 'PDO') { $db->exec($begin); }
        $db->exec('INSERT INTO caller_probe (value) VALUES (7)');
        $archive = $prefix . '-archive'; $scratch = $prefix . '-scratch.sqlite'; $caught = null;
        try {
            if ($operation === 'export') { DatabaseTransfer::export($db, $vault, $archive, $scratch, offline: true); }
            if ($operation !== 'export') { DatabaseTransfer::preflight($db, $vault, $scratch, offline: true); }
        } catch (\Throwable $error) { $caught = $error; }
        Assert::true($caught instanceof \LogicException || $caught instanceof \PDOException);
        Assert::false(file_exists($archive));
        Assert::false(file_exists($scratch));
        Assert::same(\Tablo\ServerSchema::rows($db, 'PRAGMA query_only'), [['query_only' => 0]]);
        Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT value FROM caller_probe'), [['value' => 7]]);
        Assert::true($db->exec('COMMIT') !== false);
        $observer = new \PDO('sqlite:' . $prefix . '.sqlite',
            options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        Assert::same(\Tablo\ServerSchema::rows($observer, 'SELECT value FROM caller_probe'), [['value' => 7]]);
    } finally { $observer = null; $db = null; }
}
