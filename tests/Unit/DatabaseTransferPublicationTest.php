<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\Tests\Support\CommandOptions;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TransferPublicationFault;
use Tablo\Tests\Support\TransferSqliteFixture;
use Testo\Assert;
use Testo\Test;

final class DatabaseTransferPublicationTest
{
    #[Test]
    public function publishedImportSurvivesCleanupFailureAndRefusesCompetingTargets(): void
    {
        $modes = ['normal', 'cleanup-false', 'cleanup-throw', 'link-false', 'existing', 'competing'];
        $mode = getenv('TABLO_TEST_TRANSFER_PUBLICATION_CASE');
        if (!is_string($mode) || !in_array($mode, $modes, strict: true)) {
            database_transfer_publication_owned_staging();
            self::children($modes, __FUNCTION__);
            return;
        }
        require_once dirname(__DIR__) . '/fixtures/transfer-publication-functions.php';
        $fixture = new TransferSqliteFixture();
        try {
            $source = $fixture->source('source.sqlite');
            $archive = $fixture->path('archive'); $scratch = $fixture->path('scratch.sqlite'); $target = $fixture->path('target.sqlite');
            $digest = DatabaseTransfer::export($source, $fixture->vault, $archive, $fixture->path('export.sqlite'), offline: true);
            $source = null;
            if ($mode === 'existing') { file_put_contents($target, TransferPublicationFault::COMPETING_BYTES); }
            TransferPublicationFault::arm($mode, $scratch, $target);
            $caught = null; $result = null;
            try { $result = DatabaseTransfer::importSqlite($fixture->vault, $archive, $scratch, $target, offline: true); }
            catch (\Throwable $error) { $caught = $error; }
            if (!in_array($mode, ['normal', 'cleanup-false', 'cleanup-throw'], strict: true)) {
                self::refused($mode, $caught, $result, $scratch, $target);
                return;
            }
            Assert::same($caught, null); Assert::same($result, $digest);
            $db = new PDO('sqlite:' . $target, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            Assert::same(DatabaseTransfer::verify($db, $fixture->vault, $archive, $fixture->path('verify.sqlite')), $digest);
            Assert::same(file_exists($scratch), $mode !== 'normal');
            if (file_exists($scratch)) {
                Assert::same(hash_file('sha256', $scratch), hash_file('sha256', $target));
                if (PHP_OS_FAMILY !== 'Windows') { Assert::same(fileperms($scratch) & 0o777, 0o600); }
            }
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $source = null; $db = null; $fixture->directory->close(); }
    }

    /** @param list<string> $modes */
    private static function children(array $modes, string $function): void
    {
        $directory = new TemporaryDirectory('tablo-transfer-publication-child-');
        try {
            foreach ($modes as $case) {
                $result = Subprocess::run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/vendor/bin/testo', 'run',
                    '--path', __FILE__, '--filter', $function, '--json'], $directory,
                    ['TABLO_TEST_TRANSFER_PUBLICATION_CASE' => $case, 'TABLO_TOKEN_KEY_FILE' => ''], new CommandOptions(timeout: 20));
                Assert::same($result['exit_code'], 0, $case . ': ' . $result['stdout'] . $result['stderr']);
                Assert::false(str_contains($result['stdout'] . $result['stderr'], 'synthetic-private-cleanup-'));
                $report = \Tablo\JsonRecord::decode($result['stdout']);
                $totals = \Tablo\JsonRecord::checked($report['totals'] ?? null);
                Assert::same(\Tablo\SqlColumn::integer($totals, 'total'), 1);
                Assert::same(\Tablo\SqlColumn::integer($totals, 'passed'), 1);
                Assert::true(\Tablo\SqlColumn::integer($totals, 'assertions') >= 4);
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }

    private static function refused(string $mode, ?\Throwable $caught, ?string $result, string $scratch, string $target): void
    {
        Assert::true($caught instanceof DatabaseFailure);
        if (!$caught instanceof DatabaseFailure) { throw new \RuntimeException('Expected typed publication refusal.'); }
        Assert::same($caught->category, $mode === 'existing' ? 'configuration' : 'unavailable');
        Assert::same($caught->getPrevious(), null); Assert::same($result, null);
        if ($mode === 'link-false') { Assert::false(file_exists($target)); }
        if ($mode !== 'link-false') { Assert::same(file_get_contents($target), TransferPublicationFault::COMPETING_BYTES); }
        Assert::same(file_exists($scratch), $mode !== 'existing');
    }
}


/** Exercise real private SQLite and public preflight/publication boundaries under the existing identity. */
function database_transfer_publication_owned_staging(): void
{
    $fixture = new TransferSqliteFixture(); $source = null; $scratch = null; $reader = null;
    try {
        $source = $fixture->source('batch-source.sqlite'); $archive = $fixture->path('batch-archive');
        DatabaseTransfer::export($source, $fixture->vault, $archive, $fixture->path('batch-export.sqlite'), offline: true);
        $manifest = \Tablo\DatabaseTransferVerification::manifest($archive, $fixture->vault);
        $scratch = \Tablo\DatabaseTransferPreflight::scratch($fixture->path('batch-visible.sqlite'));
        $owner = \Tablo\database_transfer_private_scratch_owner($scratch);
        try { \Tablo\DatabaseTransferVerification::scratch($scratch, $archive, $manifest['header'], $fixture->vault); $owner->commit(); }
        catch (\Throwable $error) { $owner->rollbackPreserving(); throw $error; }
        Assert::false(\Tablo\WriteTransaction::owns($scratch));
        $reader = new PDO('sqlite:' . $fixture->path('batch-visible.sqlite'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        Assert::same(\Tablo\ServerSchema::rows($reader, 'SELECT id,typeof(id) AS kind FROM users'), [['id' => 1, 'kind' => 'integer']]);
        Assert::same(\Tablo\ServerSchema::rows($reader, 'SELECT name FROM git_tokens'), [['name' => 'Synthetic']]);
        $scratch->exec('BEGIN DEFERRED'); $scratch->exec('ROLLBACK'); $reader = null; $scratch = null; $owner = null;
        $source = null;
        $source = $fixture->source('batch-borrowed.sqlite');
        database_transfer_publication_borrowed_source($fixture, $source);
        $source = null;
        foreach (['constraint', 'key'] as $mode) { database_transfer_publication_batch_refusal($fixture, $archive, $mode); }
        database_transfer_publication_private_ownership($fixture);
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $source = null; $scratch = null; $reader = null; $fixture->directory->close(); }
}

function database_transfer_publication_borrowed_source(TransferSqliteFixture $fixture, PDO $source): void
{
    Assert::true($source->beginTransaction());
    $source->exec("UPDATE users SET password_hash = 'synthetic-owned-uncommitted' WHERE id = 1"); $caught = null;
    try { DatabaseTransfer::preflight($source, $fixture->vault, $fixture->path('borrowed-refused.sqlite'), offline: true); }
    catch (\Throwable $error) { $caught = $error; }
    Assert::instanceOf($caught, \LogicException::class); Assert::true($source->inTransaction());
    Assert::same(\Tablo\ServerSchema::rows($source, 'SELECT password_hash FROM users'), [['password_hash' => 'synthetic-owned-uncommitted']]);
    Assert::false(file_exists($fixture->path('borrowed-refused.sqlite'))); Assert::true($source->rollBack());
}

function database_transfer_publication_batch_refusal(TransferSqliteFixture $fixture, string $archive, string $mode): void
{
    $table = $mode === 'constraint' ? 'users' : 'git_tokens'; $chunk = $archive . '/' . $table . '.0.jsonl';
    $originalChunk = \Tablo\RequiredFile::bytes(file_get_contents($chunk));
    $originalManifest = \Tablo\RequiredFile::bytes(file_get_contents($archive . '/manifest.json'));
    $target = new TransferTargetTripwirePdo($fixture->path('batch-server-' . $mode . '.sqlite'));
    TransferSqliteFixture::seed($target, $fixture->vault); $before = TransferSqliteFixture::snapshot($target);
    try {
        database_transfer_publication_batch_archive($archive, $mode, $originalChunk);
        $target->arm(); $caught = null; $serverScratch = $fixture->path('batch-server-stage-' . $mode . '.sqlite');
        try { DatabaseTransfer::importServer($target, $fixture->vault, $archive, $serverScratch, offline: true); }
        catch (DatabaseFailure $error) { $caught = $error; }
        $target->disarm();
        Assert::instanceOf($caught, DatabaseFailure::class); Assert::same($target->targetCalls, 0);
        Assert::same(TransferSqliteFixture::snapshot($target), $before);
        database_transfer_publication_rolled_back($serverScratch);
        $scratch = $fixture->path('batch-sqlite-stage-' . $mode . '.sqlite'); $published = $fixture->path('batch-target-' . $mode . '.sqlite'); $caught = null;
        try { DatabaseTransfer::importSqlite($fixture->vault, $archive, $scratch, $published, offline: true); }
        catch (DatabaseFailure $error) { $caught = $error; }
        Assert::instanceOf($caught, DatabaseFailure::class); Assert::same($caught->category, $mode === 'constraint' ? 'unavailable' : 'schema');
        Assert::null($caught->getPrevious()); Assert::false(file_exists($published));
        database_transfer_publication_rolled_back($scratch);
    } finally {
        $target->disarm(); $target = null;
        Assert::same(file_put_contents($chunk, $originalChunk), strlen($originalChunk));
        Assert::same(file_put_contents($archive . '/manifest.json', $originalManifest), strlen($originalManifest));
    }
}

function database_transfer_publication_rolled_back(string $path): void
{
    $db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['users', 'git_tokens', 'notification_settings'] as $table) {
        Assert::same(\Tablo\DatabaseTransferSequence::scalar($db, 'SELECT COUNT(*) AS value FROM ' . $table), 0);
    }
    Assert::false(\Tablo\WriteTransaction::owns($db)); $db->exec('BEGIN DEFERRED'); $db->exec('ROLLBACK');
}

function database_transfer_publication_batch_archive(string $archive, string $mode, string $original): void
{
    $table = $mode === 'constraint' ? 'users' : 'git_tokens'; $rows = $mode === 'constraint' ? 2 : 1;
    $bytes = $original . $original;
    if ($mode === 'key') {
        $cells = \Tablo\JsonRecord::decode(rtrim($original, characters: "\n"));
        $column = array_search('encrypted_token', array_column(TransferSqliteFixture::table('git_tokens')['columns'], 'name'), strict: true);
        if (!is_int($column)) { throw new \RuntimeException('Actual credential column missing.'); }
        $cells[$column] = ['bytes', base64_encode('synthetic-damaged-cipher')]; $bytes = \Tablo\JsonEncoding::encode($cells) . "\n";
    }
    $envelope = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($archive . '/manifest.json')));
    $header = \Tablo\JsonRecord::checked($envelope['payload'] ?? null); $tables = \Tablo\JsonRecord::checked($header['tables'] ?? null);
    foreach (array_keys($tables) as $index) {
        $receipt = \Tablo\JsonRecord::checked($tables[$index] ?? null);
        if (($receipt['name'] ?? null) !== $table) { continue; }
        $receipt['rows'] = $rows; $receipt['sha256'] = hash('sha256', $bytes);
        $receipt['chunks'] = [['rows' => $rows, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]];
        $tables[$index] = $receipt;
    }
    $header['tables'] = $tables; $wire = \Tablo\JsonEncoding::encode(['payload' => $header, 'sha256' => hash('sha256', \Tablo\JsonEncoding::encode($header))]);
    Assert::same(file_put_contents($archive . '/' . $table . '.0.jsonl', $bytes), strlen($bytes));
    Assert::same(file_put_contents($archive . '/manifest.json', $wire), strlen($wire));
}

function database_transfer_publication_private_ownership(TransferSqliteFixture $fixture): void
{
    $scratch = \Tablo\DatabaseTransferPreflight::scratch($fixture->path('batch-owner.sqlite'));
    Assert::false($scratch->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($scratch));
    Assert::same(\Tablo\ServerSchema::rows($scratch, 'PRAGMA foreign_keys'), [['foreign_keys' => 1]]);
    $reader = new PDO('sqlite:' . $fixture->path('batch-owner.sqlite'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    Assert::same(\Tablo\SchemaShape::objects($reader), \Tablo\SchemaShape::objects(\Tablo\SchemaReference::reference(11, ['endpoint' => true])));
    foreach (\Tablo\DatabaseTransferManifest::TABLES as $table) {
        Assert::same(\Tablo\DatabaseTransferSequence::scalar($reader, 'SELECT COUNT(*) AS value FROM `' . $table . '`'), 0);
    }
    $reader = null;
    $scratch->exec('BEGIN DEFERRED'); $scratch->exec("INSERT INTO users VALUES (1,'synthetic-raw-owner','2026-01-01T00:00:00Z')"); $caught = null;
    try { \Tablo\database_transfer_private_scratch_owner($scratch); } catch (\Throwable $error) { $caught = $error; }
    Assert::true($caught instanceof \LogicException || $caught instanceof \PDOException); Assert::false(\Tablo\WriteTransaction::owns($scratch));
    Assert::same(\Tablo\DatabaseTransferSequence::scalar($scratch, 'SELECT COUNT(*) AS value FROM users'), 1);
    $scratch->exec('ROLLBACK'); Assert::same(\Tablo\DatabaseTransferSequence::scalar($scratch, 'SELECT COUNT(*) AS value FROM users'), 0);
    $owner = \Tablo\database_transfer_private_scratch_owner($scratch); $caught = null;
    try { \Tablo\database_transfer_private_scratch_owner($scratch); } catch (\Throwable $error) { $caught = $error; }
    Assert::instanceOf($caught, \LogicException::class); Assert::true(\Tablo\WriteTransaction::owns($scratch)); $owner->rollback();
    database_transfer_publication_failed_private_commit($scratch);
}

function database_transfer_publication_failed_private_commit(PDO $scratch): void
{
    $owner = \Tablo\database_transfer_private_scratch_owner($scratch); $scratch->exec('PRAGMA defer_foreign_keys = ON');
    $scratch->exec('INSERT INTO worker_progress (site_id,config_revision) VALUES (999,0)'); $caught = null;
    try { $owner->commit(); } catch (\PDOException $error) { $caught = $error; }
    Assert::instanceOf($caught, \PDOException::class); Assert::true(\Tablo\WriteTransaction::owns($scratch));
    $owner->rollbackPreserving(); Assert::false(\Tablo\WriteTransaction::owns($scratch));
    Assert::same(\Tablo\DatabaseTransferSequence::scalar($scratch, 'SELECT COUNT(*) AS value FROM worker_progress'), 0);
    $scratch->exec('BEGIN DEFERRED'); $scratch->exec('ROLLBACK');
}
