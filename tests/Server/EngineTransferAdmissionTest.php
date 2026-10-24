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
        engine_transfer_archive_writing($table, $sites);
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
            Assert::same(engine_transfer_archive_visit($directory->path, $table, $receipt), $expected);
        }
        engine_transfer_archive_authentication($directory->path, $table, $lines[0], $lines[1]);
        engine_transfer_archive_framing($directory->path, $table, $lines[0], $lines[1]);
        engine_transfer_archive_numeric_object($directory->path, $table, $rows[0]);
        engine_transfer_archive_completion($directory->path, $table, $rows[0], $lines[0], $lines[1]);
        engine_transfer_archive_large($directory->path, $sites);
        engine_transfer_archive_callback($directory->path, $table, $rows, $lines);
        engine_transfer_codec_lines($table, $sites);
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
    foreach ([false, true] as $visit) {
        $seen = []; $caught = null;
        try {
            if ($visit) {
                DatabaseTransferArchive::visit($directory, $table, $receipt, static function (array $values) use (&$seen): void { $seen[] = $values; });
            }
            if (!$visit) {
                foreach (DatabaseTransferArchive::read($directory, $table, $receipt) as $values) { $seen[] = $values; }
            }
        } catch (DatabaseFailure|\JsonException $error) { $caught = $error; }
        Assert::same($caught === null ? null : $caught::class, $expectedFailure);
        if ($caught instanceof DatabaseFailure) { Assert::same($caught->category, 'schema'); }
        if ($caught instanceof \JsonException) { Assert::same($caught->getCode(), JSON_ERROR_SYNTAX); }
        Assert::same($caught?->getPrevious(), null);
        Assert::same(count($seen), $expectedRows);
    }
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

/** The typed decoder accepts these numeric keys; exact wire re-encoding must still refuse the object.
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $values */
function engine_transfer_archive_numeric_object(string $directory, array $table, array $values): void
{
    $object = JsonEncoding::encode((object) DatabaseTransferRows::encode($values));
    Assert::same(DatabaseTransferRows::decode(JsonRecord::decode($object), $table), $values);
    $receipt = engine_transfer_archive_fixture($directory, $table, [$object . "\n"], [1]);
    engine_transfer_archive_refuses($directory, $table, $receipt, expectedRows: 0);
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
    $empty = engine_transfer_archive_fixture($directory, $table, chunks: [], counts: []);
    $empty['sha256'] = str_repeat('0', times: 64);
    engine_transfer_archive_refuses($directory, $table, $empty, expectedRows: 0);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_archive_large(string $directory, array $table): void
{
    $rows = [engine_transfer_archive_site($table, id: 1), engine_transfer_archive_site($table, id: 2),
        engine_transfer_archive_site($table, id: 3), engine_transfer_archive_site($table, id: 4)];
    $bytes = engine_transfer_archive_line($rows[0]) . engine_transfer_archive_line($rows[1]);
    Assert::true(strlen($bytes) > DatabaseTransferManifest::CHUNK_BYTES - 32_768);
    Assert::true(strlen($bytes) <= DatabaseTransferManifest::CHUNK_BYTES);
    $tail = engine_transfer_archive_line($rows[2]) . engine_transfer_archive_line($rows[3]);
    $receipt = engine_transfer_archive_fixture($directory, $table, [$bytes, $tail], [2, 2]);
    Assert::same(iterator_to_array(DatabaseTransferArchive::read($directory, $table, $receipt)), $rows);
    Assert::same(engine_transfer_archive_visit($directory, $table, $receipt), $rows);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
 * @return list<\Tablo\DatabaseTransferRows::Values> */
function engine_transfer_archive_visit(string $directory, array $table, array $receipt): array
{
    $seen = [];
    DatabaseTransferArchive::visit($directory, $table, $receipt, static function (array $values) use (&$seen): void { $seen[] = $values; });
    return $seen;
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param list<\Tablo\DatabaseTransferRows::Values> $rows
 * @param list<string> $lines */
function engine_transfer_archive_callback(string $directory, array $table, array $rows, array $lines): void
{
    $receipt = engine_transfer_archive_fixture($directory, $table, [implode('', $lines)], [count($rows)]);
    $failure = new \RuntimeException('Synthetic visitor failure.'); $caught = null; $seen = [];
    try {
        DatabaseTransferArchive::visit($directory, $table, $receipt, static function (array $values) use (&$seen, $failure): void {
            $seen[] = $values;
            if (count($seen) === 2) { throw $failure; }
        });
    } catch (\RuntimeException $error) { $caught = $error; }
    Assert::same($caught, $failure); Assert::same($seen, array_slice($rows, offset: 0, length: 2));
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferManifest::Table $sites */
function engine_transfer_codec_lines(array $table, array $sites): void
{
    $values = ["synthetic:\xFF\0A ", PHP_INT_MIN, PHP_INT_MAX];
    $canonical = JsonEncoding::encode(DatabaseTransferRows::encode($values));
    Assert::same(DatabaseTransferRows::line($canonical, $table), $values);
    $object = JsonEncoding::encode((object) DatabaseTransferRows::encode($values));
    $lines = [$canonical, $object, ' ' . $canonical, $canonical . "\r", $canonical . "\n", str_replace('bytes', replace: '\\u0062ytes', subject: $canonical),
        str_replace('\\/', replace: '/', subject: $canonical),
        '[["bytes","eA=="],["bytes","MQ=="],["integer","2"]]', '[["bytes","eA"],["integer","1"],["integer","2"]]',
        '[["bytes","eA==="],["integer","1"],["integer","2"]]', '[{"0":"bytes","1":"eA=="},["integer","1"],["integer","2"]]',
        '{"0":["bytes","eQ=="],"0":["bytes","eA=="],"1":["integer","1"],"2":["integer","2"]}',
        ' [["bytes",1],["integer","1"],["integer","2"]]', '[["bytes",1e400],["integer","1"],["integer","2"]]',
        '[1e400,["integer","1"],["integer","2"]]', '[true,null,{}]', '[[],[],[]]', '[]', '{}', 'null',
        "[[\"bytes\",\"\xFF\"],[\"integer\",\"1\"],[\"integer\",\"2\"]]", str_repeat('[', times: 17) . str_repeat(']', times: 17)];
    foreach ($lines as $line) { engine_transfer_codec_equivalent($line, $table); }
    $row = engine_transfer_archive_site($sites, id: 1);
    $large = JsonEncoding::encode(DatabaseTransferRows::encode($row));
    Assert::same(DatabaseTransferRows::line($large, $sites), $row);
    engine_transfer_codec_equivalent($large, $sites);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_codec_equivalent(string $original, array $table): void
{
    $line = $original; $consumed = $original;
    $public = static function () use (&$line, $table): array { return DatabaseTransferRows::line($line, $table); };
    $consume = static function () use (&$consumed, $table): array { return DatabaseTransferRows::consumeLine($consumed, $table); };
    Assert::same(engine_transfer_codec_outcome($public), engine_transfer_codec_outcome($consume));
    Assert::same($line, $original);
    $parsed = true;
    try { JsonRecord::decode($original); } catch (\JsonException|\RuntimeException) { $parsed = false; }
    Assert::same($consumed, $parsed ? '' : $original);
}

/** @param \Closure(): \Tablo\DatabaseTransferRows::Values $decode
 * @return array{values: null|\Tablo\DatabaseTransferRows::Values, failure: null|class-string<\Throwable>, message: ?string, category: ?string, previous: bool} */
function engine_transfer_codec_outcome(\Closure $decode): array
{
    try {
        $values = $decode();
        return ['values' => $values, 'failure' => null, 'message' => null, 'category' => null, 'previous' => false];
    } catch (\Throwable $error) {
        return ['values' => null, 'failure' => $error::class, 'message' => $error->getMessage(),
            'category' => $error instanceof DatabaseFailure ? $error->category : null, 'previous' => $error->getPrevious() !== null];
    }
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

/** @param \Tablo\DatabaseTransferManifest::Table $small
 * @param \Tablo\DatabaseTransferManifest::Table $sites */
function engine_transfer_archive_writing(array $small, array $sites): void
{
    $directory = new TemporaryDirectory('tablo-transfer-chunk-writer-');
    try {
        engine_transfer_archive_write_case($directory->path . '/empty', $small, rows: [], partitions: []);
        $rows = [];
        for ($id = 1; $id <= 129; $id++) { $rows[] = ['key-' . str_pad((string) $id, length: 3, pad_string: '0', pad_type: STR_PAD_LEFT), 1, 2]; }
        engine_transfer_archive_write_case($directory->path . '/row-boundary', $small, $rows,
            [array_slice($rows, offset: 0, length: 128), [$rows[128] ?? throw new \RuntimeException('Synthetic boundary row absent.')]]);
        $large = [engine_transfer_archive_site($sites, id: 1), engine_transfer_archive_site($sites, id: 2), engine_transfer_archive_site($sites, id: 3)];
        engine_transfer_archive_write_case($directory->path . '/byte-boundary', $sites, $large, [array_slice($large, offset: 0, length: 2), [$large[2]]]);
        engine_transfer_archive_budget_case($directory->path . '/zero-budget', $small, [['first', 1, 2]], limit: 0, expectedRows: 0);
        engine_transfer_archive_budget_case($directory->path . '/one-budget', $sites, $large, limit: 1, expectedRows: 2);
        engine_transfer_archive_write_collision($directory->path . '/collision', $small);
        engine_transfer_archive_write_failure($directory->path . '/row-failure', $small);
    } finally { $directory->close(); }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param list<\Tablo\DatabaseTransferRows::Values> $rows */
function engine_transfer_archive_database(array $table, array $rows = []): \PDO
{
    $db = \Tablo\SchemaReference::reference(10, ['endpoint' => true]);
    $db->exec('PRAGMA foreign_keys = ON');
    foreach ($rows as $values) { DatabaseTransferRows::insert($db, $table, $values); }
    return $db;
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param list<\Tablo\DatabaseTransferRows::Values> $rows
 * @param list<list<\Tablo\DatabaseTransferRows::Values>> $partitions */
function engine_transfer_archive_write_case(string $directory, array $table, array $rows, array $partitions): void
{
    Assert::true(mkdir($directory, permissions: 0o700));
    $source = engine_transfer_archive_database($table, $rows);
    $measured = DatabaseTransferArchive::measure($source, engine_transfer_archive_database($table), $table, remainingChunks: count($partitions));
    $written = DatabaseTransferArchive::write($source, engine_transfer_archive_database($table), $table, $directory, remainingChunks: count($partitions));
    Assert::same($written, $measured);
    Assert::same($written, engine_transfer_archive_expected($directory, $table['name'], $partitions));
    Assert::same(iterator_to_array(DatabaseTransferArchive::read($directory, $table, $written)), $rows);
    Assert::same(engine_transfer_archive_visit($directory, $table, $written), $rows);
    Assert::false(file_exists($directory . '/' . $table['name'] . '.' . count($partitions) . '.jsonl'));
}

/** Expected partitions are explicit test inputs, independent of the writer's boundary algorithm.
 * @param list<list<\Tablo\DatabaseTransferRows::Values>> $partitions
 * @return \Tablo\DatabaseTransferArchive::Receipt */
function engine_transfer_archive_expected(string $directory, string $name, array $partitions): array
{
    $chunks = []; $rows = 0; $hash = hash_init('sha256');
    foreach ($partitions as $index => $partition) {
        $bytes = implode('', array_map(engine_transfer_archive_line(...), $partition));
        $path = $directory . '/' . $name . '.' . $index . '.jsonl';
        Assert::same(RequiredFile::bytes(file_get_contents($path)), $bytes);
        engine_transfer_archive_permissions($path);
        $chunks[] = ['rows' => count($partition), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $rows += count($partition); hash_update($hash, $bytes);
    }
    return ['name' => $name, 'rows' => $rows, 'sha256' => hash_final($hash), 'chunks' => $chunks];
}

function engine_transfer_archive_permissions(string $path): void
{
    if (PHP_OS_FAMILY === 'Windows') { return; }
    $permissions = fileperms($path);
    if ($permissions === false) { throw new \RuntimeException('Synthetic chunk permissions unavailable.'); }
    Assert::same($permissions & 0o777, 0o600);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param list<\Tablo\DatabaseTransferRows::Values> $rows */
function engine_transfer_archive_budget_case(string $directory, array $table, array $rows, int $limit, int $expectedRows): void
{
    Assert::true(mkdir($directory, permissions: 0o700));
    $source = engine_transfer_archive_database($table, $rows);
    foreach (['write', 'measure'] as $operation) {
        $scratch = engine_transfer_archive_database($table); $caught = null;
        try {
            if ($operation === 'write') { DatabaseTransferArchive::write($source, $scratch, $table, $directory, remainingChunks: $limit); }
            if ($operation === 'measure') { DatabaseTransferArchive::measure($source, $scratch, $table, remainingChunks: $limit); }
        } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::same(engine_transfer_archive_count($scratch, $table['name']), $expectedRows);
    }
    Assert::same(file_exists($directory . '/' . $table['name'] . '.0.jsonl'), $expectedRows !== 0);
    Assert::false(file_exists($directory . '/' . $table['name'] . '.1.jsonl'));
    if ($expectedRows !== 0) {
        $expected = implode('', array_map(engine_transfer_archive_line(...), array_slice($rows, offset: 0, length: $expectedRows)));
        Assert::same(RequiredFile::bytes(file_get_contents($directory . '/' . $table['name'] . '.0.jsonl')), $expected);
    }
}

function engine_transfer_archive_count(\PDO $db, string $table): int
{
    return \Tablo\DatabaseInteger::checked(\Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM `' . $table . '`'))->fetchColumn());
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_archive_write_collision(string $directory, array $table): void
{
    Assert::true(mkdir($directory, permissions: 0o700));
    $path = $directory . '/' . $table['name'] . '.0.jsonl';
    DatabaseTransferArchive::privateFile($path, 'occupied');
    $source = engine_transfer_archive_database($table, [['first', 1, 2], ['second', 3, 4]]);
    $scratch = engine_transfer_archive_database($table); $caught = null;
    try { DatabaseTransferArchive::write($source, $scratch, $table, $directory); }
    catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'configuration'); Assert::same($caught?->getPrevious(), null);
    Assert::same(RequiredFile::bytes(file_get_contents($path)), 'occupied');
    Assert::same(engine_transfer_archive_count($scratch, $table['name']), 1);
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function engine_transfer_archive_write_failure(string $directory, array $table): void
{
    Assert::true(mkdir($directory, permissions: 0o700));
    $rows = [['first', 1, 2], ['second', 3, 4]];
    $source = engine_transfer_archive_database($table, $rows);
    $scratch = engine_transfer_archive_database($table, [$rows[1]]); $caught = null;
    $streams = count(get_resources(type: 'stream'));
    try { DatabaseTransferArchive::write($source, $scratch, $table, $directory); }
    catch (\PDOException $error) { $caught = $error; }
    Assert::same($caught?->getCode(), '23000');
    Assert::same(count(get_resources(type: 'stream')), $streams);
    $path = $directory . '/' . $table['name'] . '.0.jsonl';
    Assert::same(RequiredFile::bytes(file_get_contents($path)), engine_transfer_archive_line($rows[0]));
    Assert::same(engine_transfer_archive_count($scratch, $table['name']), 2);
    engine_transfer_archive_permissions($path);
    Assert::false(file_exists($directory . '/manifest.json'));
}
