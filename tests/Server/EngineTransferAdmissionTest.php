<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransferArchive;
use Tablo\DatabaseTransferManifest;
use Tablo\DatabaseTransferRows;
use Tablo\DatabaseTransferVerification;
use Tablo\JsonEncoding;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class EngineTransferAdmissionTest
{
    #[Test]
    public function invalidUtcCodecAlwaysReturnsAFreshSanitizedSchemaFailure(): void
    { engine_transfer_invalid_utc_codec(); }

    #[Test]
    public function unknownHeaderKeysAndNoncanonicalManifestBytesRefuseBelowTheSizeLimit(): void
    {
        $fixture = new EngineFixture('transfer-manifest-shape'); $directory = EngineTransferEnvelopeTest::archive($fixture);
        $original = RequiredFile::bytes(file_get_contents($directory . '/manifest.json'));
        $envelope = JsonRecord::decode($original); $header = JsonRecord::checked($envelope['payload'] ?? null);
        $header['unexpected'] = 'bounded'; EngineTransferEnvelopeTest::manifest($directory, $header);
        $caught = null;
        try { DatabaseTransferVerification::manifest($directory, $fixture->vault); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema');
        $wire = ' ' . $original; Assert::true(strlen($wire) < DatabaseTransferManifest::MANIFEST_BYTES);
        Assert::same(file_put_contents($directory . '/manifest.json', $wire), strlen($wire));
        $failure = null;
        try { DatabaseTransferVerification::manifest($directory, $fixture->vault); } catch (DatabaseFailure $error) { $failure = $error; }
        Assert::same($failure?->category, 'schema'); $fixture->close();
    }

    #[Test]
    public function explicitTransferSourceIsReadonlyAndKeepsTheNormalWriterIndependent(): void
    {
        $fixture = new EngineFixture('transfer-readonly-source');
        $writer = $fixture->database(); $before = $fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $writer);
        $source = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), mode: 'transfer-source', vault: $fixture->vault);
        $bufferAttribute = \Tablo\DatabaseInteger::checked(constant((PHP_VERSION_ID >= 80_400 ? 'Pdo\\Mysql::ATTR_' : 'PDO::MYSQL_ATTR_') . 'USE_BUFFERED_QUERY'));
        Assert::true(in_array($source->getAttribute($bufferAttribute), [false, 0], strict: true));
        $source->beginTransaction(); $native = null;
        try {
            try { $source->exec('UPDATE installation_settings SET check_interval_minutes = 99'); }
            catch (\PDOException $error) { $native = \Tablo\DatabaseInteger::checked($error->errorInfo[1] ?? null); }
            Assert::same($native, 1792);
        } finally { $source->rollBack(); }
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $writer), $before);
        \Tablo\WriteTransaction::run($writer, static fn (): int|false => $writer->exec('UPDATE installation_settings SET check_interval_minutes = 17'));
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $writer), 17);
        $fixture->close();
    }

    #[Test]
    public function binaryCellsPreserveOpaqueBytesWhileUtf8AndUtcRemainValidated(): void
    {
        $table = self::table('login_limits');
        Assert::same($table['name'], 'login_limits');
        $column = DatabaseTransferManifest::columns($table)[0] ?? throw new \RuntimeException('Fixed binary primary key absent.');
        Assert::same($column['type'], 'varbinary(128)');
        $opaque = "synthetic:\xFF\0A ";
        Assert::same(\Tablo\DatabaseTransferRows::value($opaque, $column), $opaque);
        $values = [$opaque, 1, 2];
        Assert::same(\Tablo\DatabaseTransferRows::decode(\Tablo\DatabaseTransferRows::encode($values), $table), $values);
        $sites = self::table('sites');
        $text = DatabaseTransferManifest::columns($sites)[1] ?? throw new \RuntimeException('Fixed UTF-8 name absent.');
        Assert::same([$sites['name'], $text['name'], $text['type']], ['sites', 'name', 'longtext']);
        $users = self::table('users');
        $utc = DatabaseTransferManifest::columns($users)[2] ?? throw new \RuntimeException('Fixed canonical UTC absent.');
        Assert::same([$utc['name'], $utc['type']], ['created_at', 'varbinary(20)']);
        Assert::same(\Tablo\DatabaseTransferRows::value('0001-01-01T00:00:00Z', $utc), '0001-01-01T00:00:00Z');
        foreach ([[$opaque, $text], [$opaque, $utc], [str_repeat('x', times: 129), $column]] as [$invalid, $shape]) {
            $failure = null;
            try { \Tablo\DatabaseTransferRows::value($invalid, $shape); } catch (DatabaseFailure $error) { $failure = $error; }
            Assert::same($failure?->category, 'schema');
        }
        engine_transfer_archive_lines($table, $sites);
    }

    #[Test]
    public function opaqueBinaryPrimaryKeyRoundTripPreservesNulNonUtf8AndTrailingBytes(): void
    {
        $fixture = new EngineFixture('transfer-binary-primary');
        try {
            $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/' . $fixture->case;
            $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault); $opaque = "synthetic:\xFF\0A ";
            \Tablo\ServerSchema::rows($source, 'UPDATE login_limits SET `key` = ?', [$opaque]);
            Assert::same(\Tablo\ServerSchema::rows($source, 'SELECT `key`,typeof(`key`) AS storage FROM login_limits'), [['key' => $opaque, 'storage' => 'text']]);
            $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
            Assert::same(\Tablo\DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/archive', $root . '/import.sqlite', offline: true), $digest);
            $db = $fixture->database(); Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT `key` FROM login_limits'), [['key' => $opaque]]);
            $reverse = \Tablo\DatabaseTransfer::export($fixture->independent(), $fixture->vault, $root . '/reverse', $root . '/reverse-export.sqlite', offline: true);
            Assert::same(\Tablo\DatabaseTransfer::importSqlite($fixture->vault, $root . '/reverse', $root . '/staging.sqlite', $root . '/target.sqlite', offline: true), $reverse);
            $target = new \PDO('sqlite:' . $root . '/target.sqlite', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            Assert::same(\Tablo\ServerSchema::rows($target, 'SELECT `key`,typeof(`key`) AS storage FROM login_limits'), [['key' => $opaque, 'storage' => 'text']]);
            Assert::same(\Tablo\DatabaseTransfer::verify($target, $fixture->vault, $root . '/archive', $root . '/verify.sqlite'), $digest);
        } finally { $fixture->close(); }
    }

    /** @return \Tablo\DatabaseTransferManifest::Table */
    private static function table(string $name): array
    {
        foreach (DatabaseTransferManifest::tables() as $table) {
            if ($table['name'] === $name) { return $table; }
        }
        throw new \RuntimeException('Fixed transfer table absent.');
    }
}

function engine_transfer_invalid_utc_codec(): void
{
    $utc = ['name' => 'created_at', 'type' => 'varbinary(20)', 'nullable' => false];
    foreach (['0001-01-01T00:00:00Z', '0099-12-31T23:59:59Z', '0999-12-31T23:59:59Z', '2000-02-29T12:00:00Z'] as $valid) {
        Assert::same(\Tablo\DatabaseTransferRows::value($valid, $utc), $valid);
    }
    foreach ([null, '', 'not-a-timestamp', '0000-01-01T00:00:00Z', '1900-02-29T00:00:00Z',
        '2026-02-30T00:00:00Z', '2026-01-01T00:00:60Z', '2026-01-01T00:00:00+00:00', "synthetic:\xFF\0"] as $invalid) {
        $caught = null;
        try { \Tablo\DatabaseTransferRows::value($invalid, $utc); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferManifest::Table $sites */
function engine_transfer_archive_lines(array $table, array $sites): void
{
    $directory = new TemporaryDirectory('tablo-transfer-chunk-codec-');
    try {
        $rows = [["synthetic:\xFF\0A \r", 1, 2], ['second', 3, 4], ['final', 5, 6]];
        $lines = array_map(engine_transfer_archive_line(...), $rows);
        foreach ([[[$lines[0]], [1], [$rows[0]]], [[$lines[0] . $lines[1]], [2], [$rows[0], $rows[1]]],
            [[$lines[0] . $lines[1], $lines[2]], [2, 1], $rows], [[$lines[0], $lines[1], $lines[2]], [1, 1, 1], $rows],
            [[], [], []]] as [$chunks, $counts, $expected]) {
            $receipt = engine_transfer_archive_fixture($directory->path, $table, $chunks, $counts);
            Assert::same(iterator_to_array(DatabaseTransferArchive::read($directory->path, $table, $receipt)), $expected);
        }
        engine_transfer_archive_authentication($directory->path, $table, $lines[0], $lines[1]);
        engine_transfer_archive_framing($directory->path, $table, $lines[0], $lines[1]);
        engine_transfer_archive_completion($directory->path, $table, $rows[0], $lines[0], $lines[1]);
        engine_transfer_archive_large($directory->path, $sites);
    } finally { $directory->close(); }
}

/** @param \Tablo\DatabaseTransferRows::Values $values */
function engine_transfer_archive_line(array $values): string
{
    return JsonEncoding::encode(DatabaseTransferRows::encode($values)) . "\n";
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param list<string> $chunks
 * @param list<int> $counts
 * @return \Tablo\DatabaseTransferArchive::Receipt */
function engine_transfer_archive_fixture(string $directory, array $table, array $chunks, array $counts): array
{
    Assert::same(count($chunks), count($counts)); $receipts = [];
    foreach ($chunks as $index => $bytes) {
        Assert::same(file_put_contents($directory . '/' . $table['name'] . '.' . $index . '.jsonl', $bytes), strlen($bytes));
        $receipts[] = ['rows' => $counts[$index] ?? throw new \RuntimeException('Synthetic chunk row count absent.'), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
    return ['name' => $table['name'], 'rows' => \Tablo\DatabaseInteger::checked(array_sum($counts)), 'sha256' => hash('sha256', implode('', $chunks)), 'chunks' => $receipts];
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
 * @param class-string<DatabaseFailure|\JsonException> $expectedFailure */
function engine_transfer_archive_refuses(string $directory, array $table, array $receipt, int $expectedRows, string $expectedFailure = DatabaseFailure::class): void
{
    $seen = []; $caught = null;
    try {
        foreach (DatabaseTransferArchive::read($directory, $table, $receipt) as $values) { $seen[] = $values; }
    } catch (DatabaseFailure|\JsonException $error) { $caught = $error; }
    Assert::same($caught === null ? null : $caught::class, $expectedFailure);
    if ($caught instanceof DatabaseFailure) { Assert::same($caught->category, 'schema'); }
    if ($caught instanceof \JsonException) { Assert::same($caught->getCode(), JSON_ERROR_SYNTAX); }
    Assert::same($caught?->getPrevious(), null);
    Assert::same(count($seen), $expectedRows);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_archive_authentication(string $directory, array $table, string $first, string $second): void
{
    $bytes = $first . $second;
    foreach (['size', 'hash', 'end'] as $fault) {
        $receipt = engine_transfer_archive_fixture($directory, $table, [$bytes], [2]);
        $chunk = $receipt['chunks'][0] ?? throw new \RuntimeException('Synthetic authentication chunk absent.');
        if ($fault === 'size') { $chunk['bytes']++; }
        if ($fault === 'hash') { $chunk['sha256'] = str_repeat('0', times: 64); }
        $receipt['chunks'][0] = $chunk;
        if ($fault === 'end') {
            $changed = substr($bytes, offset: 0, length: -1) . 'x';
            Assert::same(file_put_contents($directory . '/' . $table['name'] . '.0.jsonl', $changed), strlen($bytes));
        }
        engine_transfer_archive_refuses($directory, $table, $receipt, expectedRows: 0);
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_archive_framing(string $directory, array $table, string $first, string $second): void
{
    foreach ([[substr($first, offset: 0, length: -1), 1, 0, DatabaseFailure::class], [$first . $second, 1, 0, DatabaseFailure::class],
        ["\n" . $first, 2, 0, \JsonException::class], [$first . "\n", 2, 1, \JsonException::class],
        [substr($first, offset: 0, length: -1) . "\r\n", 1, 0, DatabaseFailure::class], [' ' . $first, 1, 0, DatabaseFailure::class]] as [$bytes, $count, $escaped, $failure]) {
        // Self-consistent hashes isolate framing and canonical row validation.
        $receipt = engine_transfer_archive_fixture($directory, $table, [$bytes], [$count]);
        engine_transfer_archive_refuses($directory, $table, $receipt, $escaped, $failure);
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $firstRow */
function engine_transfer_archive_completion(string $directory, array $table, array $firstRow, string $first, string $second): void
{
    $receipt = engine_transfer_archive_fixture($directory, $table, [$first, $second], [1, 1]);
    $changed = substr($second, offset: 0, length: -1) . 'x';
    Assert::same(file_put_contents($directory . '/' . $table['name'] . '.1.jsonl', $changed), strlen($second));
    $reader = DatabaseTransferArchive::read($directory, $table, $receipt);
    Assert::same($reader->current(), $firstRow); unset($reader);
    engine_transfer_archive_refuses($directory, $table, $receipt, expectedRows: 1);
    foreach (['digest', 'rows'] as $fault) {
        $receipt = engine_transfer_archive_fixture($directory, $table, [$first, $second], [1, 1]);
        if ($fault === 'digest') { $receipt['sha256'] = str_repeat('0', times: 64); }
        if ($fault === 'rows') { $receipt['rows']++; }
        engine_transfer_archive_refuses($directory, $table, $receipt, expectedRows: 2);
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_archive_large(string $directory, array $table): void
{
    $rows = [engine_transfer_archive_site($table, id: 1), engine_transfer_archive_site($table, id: 2)];
    $bytes = implode('', array_map(engine_transfer_archive_line(...), $rows));
    Assert::true(strlen($bytes) > DatabaseTransferManifest::CHUNK_BYTES - 32_768);
    Assert::true(strlen($bytes) <= DatabaseTransferManifest::CHUNK_BYTES);
    $receipt = engine_transfer_archive_fixture($directory, $table, [$bytes], [2]);
    Assert::same(iterator_to_array(DatabaseTransferArchive::read($directory, $table, $receipt)), $rows);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @return \Tablo\DatabaseTransferRows::Values */
function engine_transfer_archive_site(array $table, int $id): array
{
    /** @var array<string, mixed> $record Synthetic fields are checked against the public columns below. */
    $record = ['id' => $id, 'name' => 'Synthetic', 'url' => 'https://example.test', 'provider' => 'github',
        'repository' => 'synthetic/repository', 'github_token' => null, 'git_token_id' => null, 'branch' => 'main',
        'health_path' => '/up', 'version_path' => '', 'version_json_path' => '', 'health_check_mode' => 'http',
        'health_json_path' => '', 'health_json_operator' => '==', 'health_json_expected_value' => '', 'comparison_mode' => 'release',
        'enabled' => 1, 'sort_order' => 0, 'online' => null, 'deployed_version' => null, 'deployed_commit' => null,
        'latest_release' => null, 'latest_commit' => null, 'open_issues' => null, 'open_prs' => null, 'response_time_ms' => null,
        'last_error' => str_repeat('x', times: 90_000), 'checked_at' => null, 'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z', 'health_error_code' => null, 'health_http_status' => null, 'config_revision' => 0, 'endpoint_token' => null];
    $columns = DatabaseTransferManifest::columns($table); Assert::same(array_keys($record), array_column($columns, 'name'));
    $values = [];
    foreach ($columns as $column) {
        if (!array_key_exists($column['name'], $record)) { throw new \RuntimeException('Synthetic site field absent.'); }
        $values[] = DatabaseTransferRows::value($record[$column['name']], $column);
    }
    return $values;
}
