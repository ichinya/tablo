<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SchemaReference;
use Tablo\SelfUpdateRepository;
use Tablo\SelfUpdateSchema;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateMigrationTest
{
    #[Test]
    public function additiveTenToElevenPreservesRowsAndRollsBackAtEveryPublicationStage(): void
    {
        self_update_widen_journal();
        self_update_native_cardinality();
        foreach (['CREATE TABLE self_update', 'INSERT INTO self_update', 'PRAGMA main.user_version = 11', 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:'); SchemaReference::reference(10, ['endpoint' => true], $db);
            $db->exec("PRAGMA user_version=10; INSERT INTO users(id,password_hash) VALUES(1,'fixture-password-hash');
                INSERT INTO sites(name,url,repository,github_token) VALUES('preserved','https://example.test','fixture/repo','fixture-ciphertext');
                INSERT INTO read_api_token VALUES(1,'" . str_repeat('a', times: 64) . "','" . str_repeat('b', times: 64) . "','2026-10-28T00:00:00Z')");
            $before = A::snapshot($db); $db->statements = []; $db->failBefore = $failure;
            if ($failure !== null) {
                $caught = false;
                try { Database::migrate($db); } catch (\PDOException) { $caught = true; }
                Assert::true($caught); Assert::same(A::snapshot($db), $before); $db->failBefore = null;
            }
            $db->statements = []; Database::migrate($db);
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => str_starts_with($sql, 'CREATE'))), [SelfUpdateSchema::sql()]);
            $after = A::snapshot($db);
            foreach (array_keys($before) as $name) {
                if (in_array($name, ['version', 'schema'], strict: true)) { continue; }
                Assert::same($after[$name] ?? null, $before[$name] ?? null);
            }
            Assert::same((new SelfUpdateRepository($db))->get()['interval_minutes'], 360);
            $db->statements = []; Database::migrate($db);
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT)/', $sql) === 1)), []);
        }
    }
}

/** Offline source/codec evidence supplements, and never substitutes for, the selected-engine bodies. */
function self_update_native_cardinality(): void
{
    $expected = [...\Tablo\DatabaseTransferManifest::TABLES, 'tablo_database_state', 'tablo_writer_guard', 'tablo_migration_journal'];
    sort($expected, SORT_STRING);
    foreach (['mysql', 'mariadb'] as $engine) {
        $names = array_column(\Tablo\ServerSchemaDescriptor::forEngine($engine)['tables'], 'name'); sort($names, SORT_STRING);
        Assert::same($names, $expected); Assert::same(count($names), 19);
        Assert::same(count(array_diff($names, ['self_update'])), 18);
        Assert::same(count(array_diff($names, ['self_update', 'read_api_token'])), 17);
    }
    self_update_transfer_round_trip();
}

function self_update_transfer_round_trip(): void
{
    $fixture = new \Tablo\Tests\Support\TransferSqliteFixture(); $source = null; $target = null;
    try {
        $source = \Tablo\Tests\Server\EngineTransferTest::source($fixture->path('source.sqlite'), $fixture->vault);
        \Tablo\Tests\Server\engine_transfer_current_tables();
        $before = \Tablo\Tests\Server\engine_transfer_typed_digests($source);
        \Tablo\Tests\Server\engine_transfer_snapshot_tables($before);
        $saved = (new SelfUpdateRepository($source))->get();
        Assert::same([$saved['status'], $saved['new_commits'], $saved['error_code'], $saved['failures'], $saved['next_due']], ['behind', 2, 'rate-limit', 1, 90_000]);
        $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $fixture->path('archive'), $fixture->path('export.sqlite'), offline: true);
        $manifest = \Tablo\DatabaseTransferVerification::manifest($fixture->path('archive'), $fixture->vault);
        Assert::same([$manifest['header']['logicalVersion'], count($manifest['header']['tables'])], [11, 16]);
        Assert::same(\Tablo\DatabaseTransfer::importSqlite($fixture->vault, $fixture->path('archive'), $fixture->path('staging.sqlite'), $fixture->path('target.sqlite'), offline: true), $digest);
        $target = new \PDO('sqlite:' . $fixture->path('target.sqlite'), options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        Assert::same(\Tablo\Tests\Server\engine_transfer_typed_digests($target), $before);
        Assert::same((new SelfUpdateRepository($target))->get(), $saved);
    } finally { $source = null; $target = null; $fixture->directory->close(); }
}

/** Real SQLite PDO modes exercise journal decoding without claiming selected-engine execution. */
function self_update_widen_journal(): void
{
    foreach (['native', 'stringified'] as $mode) {
        $db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_STRINGIFY_FETCHES => $mode === 'stringified']);
        // BLOB affinity preserves deliberately malformed integer strings; no PK permits duplicate-row refusal controls.
        $db->exec('CREATE TABLE tablo_migration_journal(plan TEXT,step INTEGER,checksum TEXT,predecessor BLOB,target BLOB,before_predicate TEXT,after_predicate TEXT,phase TEXT)');
        $journal = new \Tablo\ServerMigrationJournal($db, 'schema11');
        foreach ([9, 10] as $predecessor) { self_update_widen_journal_replay($db, $journal, $predecessor); }
        self_update_widen_journal_invalid($db, $journal);
    }
}

function self_update_widen_journal_replay(\PDO $db, \Tablo\ServerMigrationJournal $journal, int $predecessor): void
{
    $db->exec('DELETE FROM tablo_migration_journal'); $journal->widenStateIntent($predecessor, 'checksum');
    $rows = \Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal'); Assert::same(count($rows), 1);
    Assert::same([\Tablo\DatabaseInteger::checked($rows[0]['predecessor'] ?? null), \Tablo\DatabaseInteger::checked($rows[0]['target'] ?? null)], [$predecessor, 11]);
    $ordinary = new \Tablo\ServerMigrationJournal($db, 'ordinary'); $ordinary->intent(1, 'ordinary-checksum', $predecessor, 11, 'synthetic');
    foreach (['intent', 'done', 'failed'] as $phase) {
        \Tablo\ServerSchema::rows($db, 'UPDATE tablo_migration_journal SET phase = ?', [$phase]);
        $before = \Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY plan');
        $db->exec('PRAGMA query_only = ON');
        try {
            $journal->widenStateIntent($predecessor, 'checksum'); $ordinary->intent(1, 'ordinary-checksum', $predecessor, 11, 'synthetic');
            Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY plan'), $before, 'replaying a canonical intent is read-only and preserves every phase');
        } finally { $db->exec('PRAGMA query_only = OFF'); }
    }
}

function self_update_widen_journal_invalid(\PDO $db, \Tablo\ServerMigrationJournal $journal): void
{
    $valid = ['checksum', 9, 11, 'state:logical-version-max10', 'state:logical-version-max11', 'intent'];
    $cases = [];
    foreach ([null, '09', '+9', '9.0', '9e0', ' 9', '', '9223372036854775808', 10] as $value) { $row = $valid; $row[1] = $value; $cases[] = $row; }
    foreach ([null, '011', '+11', '11.0', 10] as $value) { $row = $valid; $row[2] = $value; $cases[] = $row; }
    foreach ([0 => 'wrong-checksum', 3 => 'state:logical-version-max9', 4 => 'state:logical-version-max12', 5 => 'unknown'] as $index => $value) { $row = $valid; $row[$index] = $value; $cases[] = $row; }
    foreach ($cases as $row) { self_update_widen_journal_refusal($db, $journal, [$row]); }
    self_update_widen_journal_refusal($db, $journal, [$valid, $valid]);
}

/** @param list<list<int|string|null>> $rows */
function self_update_widen_journal_refusal(\PDO $db, \Tablo\ServerMigrationJournal $journal, array $rows): void
{
    $db->exec('DELETE FROM tablo_migration_journal');
    foreach ($rows as $row) { \Tablo\ServerSchema::rows($db, "INSERT INTO tablo_migration_journal VALUES('schema11',0,?,?,?,?,?,?)", $row); }
    $before = \Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal'); $caught = null;
    try { $journal->widenStateIntent(9, 'checksum'); } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal'), $before, 'malformed or duplicate intents refuse without repair');
}
