<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\DatabaseTransferManifest;
use Tablo\DatabaseTransferRows;
use Tablo\DatabaseTransferWire;
use Tablo\JsonEncoding;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\DatabaseTransferVerification;
use Tablo\ServerSchema;
use Tablo\Tests\Support\TransferSqliteFixture;
use Testo\Assert;
use Testo\Test;

final class DatabaseTransferNameTest
{
    /** @return list<string> */
    private static function invalidNames(): array { return ['', "synthetic\0name", "synthetic\xff", str_repeat('a', times: 81), str_repeat("\xf0\x9f\xa7\xaa", times: 81)]; }

    #[Test]
    public function varcharNamesCountCodePointsAndKeepExactPermittedBytes(): void
    {
        database_transfer_manifest_public_contract();
        database_transfer_wire_byte_oracles();
        database_transfer_codec_public_oracles();
        $column = TransferSqliteFixture::table('git_tokens')['columns'][1] ?? throw new \RuntimeException('Fixed token name absent.');
        Assert::same([$column['name'], $column['type']], ['name', 'varchar(80)']);
        $valid = ['a', str_repeat('a', times: 80), str_repeat("\xf0\x9f\xa7\xaa", times: 80), str_repeat("e\xcc\x81", times: 40), 'Café '];
        foreach ($valid as $value) { Assert::same(DatabaseTransferRows::value($value, $column), $value); }
        foreach (self::invalidNames() as $value) {
            $caught = null;
            try { DatabaseTransferRows::value($value, $column); } catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        }
    }

    #[Test]
    public function realSqliteBadNamesRefusePublicPreflightWithoutChangingSourceRows(): void
    {
        \Tablo\Tests\Support\TransferRowsAssertions::run();
        $fixture = new TransferSqliteFixture();
        try {
            foreach (self::invalidNames() as $index => $name) {
                $source = $fixture->source('source-' . $index . '.sqlite');
                ServerSchema::rows($source, 'UPDATE git_tokens SET name = ?', [$name]);
                $before = ServerSchema::rows($source, 'SELECT CAST(name AS BLOB) AS bytes,typeof(name) AS storage_type FROM git_tokens');
                Assert::same($before[0]['storage_type'] ?? null, 'text');
                $caught = null;
                try { DatabaseTransfer::preflight($source, $fixture->vault, $fixture->path('preflight-' . $index . '.sqlite'), offline: true); }
                catch (DatabaseFailure $error) { $caught = $error; unset($error); }
                Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
                Assert::same(ServerSchema::rows($source, 'SELECT CAST(name AS BLOB) AS bytes,typeof(name) AS storage_type FROM git_tokens'), $before);
                Assert::false($source->inTransaction()); $source = null; $caught = null; gc_collect_cycles();
            }
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $source = null; $fixture->directory->close(); }
    }

    #[Test]
    public function selfConsistentBadNameArchivesRefuseBeforeAnyTargetSqlOrClearing(): void
    {
        $fixture = new TransferSqliteFixture();
        try {
            $target = new TransferTargetTripwirePdo($fixture->path('target.sqlite'));
            TransferSqliteFixture::seed($target, $fixture->vault);
            $before = TransferSqliteFixture::snapshot($target);
            foreach (self::invalidNames() as $index => $name) {
                $source = $fixture->source('archive-source-' . $index . '.sqlite');
                $directory = $fixture->path('archive-' . $index);
                DatabaseTransfer::export($source, $fixture->vault, $directory, $fixture->path('export-' . $index . '.sqlite'), offline: true);
                $source = null; TransferSqliteFixture::replaceArchiveName($directory, $name);
                Assert::same(DatabaseTransferVerification::manifest($directory, $fixture->vault)['header']['logicalVersion'], 11);
                $target->arm(); $caught = null;
                try { DatabaseTransfer::importServer($target, $fixture->vault, $directory, $fixture->path('import-' . $index . '.sqlite'), offline: true); }
                catch (DatabaseFailure $error) { $caught = $error; unset($error); }
                $target->disarm();
                Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
                Assert::same($target->targetCalls, 0);
                Assert::same(TransferSqliteFixture::snapshot($target), $before);
                Assert::false($target->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($target)); $caught = null; gc_collect_cycles();
            }
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $target = null; $source = null; $fixture->directory->close(); }
    }
}

/** Public wire, shared-header ownership and mutations stay independent of cache storage. */
function database_transfer_manifest_public_contract(): void
{
    $layout = '05b522d43d8e58dd75fa86feb31892a44b3e96b531f5a66561cad016dfc74c81';
    $contractDigest = 'e06a3628181fcba44e3bdf127c838adcc10ad69aba8e9c722a26bd7f3a23ccbd';
    $tables = \Tablo\DatabaseTransferManifest::tables();
    Assert::same(count($tables), 16);
    Assert::same(hash('sha256', JsonEncoding::encode($tables)), $layout);
    Assert::same(\Tablo\DatabaseTransferManifest::layout(), $layout);
    $contract = \Tablo\DatabaseTransferManifest::contract();
    Assert::same(hash('sha256', JsonEncoding::encode($contract)), $contractDigest);
    Assert::same(iterator_to_array(\Tablo\DatabaseTransferManifest::iterate(), preserve_keys: false), $tables);
    $views = iterator_to_array(\Tablo\DatabaseTransferManifest::iterate($contract), preserve_keys: false);
    Assert::same($views, $tables);
    $views[0]['columns'][0]['name'] = 'changed-view';
    $tables[0]['columns'][0]['name'] = 'changed-table';
    $tables[0]['primary'][0] = 'changed-primary';
    Assert::same($contract[0]['columns'][0]['name'] ?? null, 'id');
    $contract[0]['columns'][0]['name'] = 'changed-contract';
    Assert::same(\Tablo\DatabaseTransferManifest::layout(), $layout);
    Assert::same(hash('sha256', JsonEncoding::encode(\Tablo\DatabaseTransferManifest::contract())), $contractDigest);
    $first = \Tablo\DatabaseTransferManifest::iterate(); $second = \Tablo\DatabaseTransferManifest::iterate();
    $first->rewind(); $second->rewind();
    Assert::same($first->current()['name'], 'users');
    $first->next();
    Assert::same($first->current()['name'], 'git_tokens');
    Assert::same($second->current()['name'], 'users');
    database_transfer_header_public_oracles();
}

/** Public manifest bytes, key order and canonical cache isolation are the oracle. */
function database_transfer_header_public_oracles(): void
{
    $fixture = new TransferSqliteFixture(); $source = null;
    try {
        $source = $fixture->source('header-source.sqlite'); $archive = $fixture->path('header-archive');
        DatabaseTransfer::export($source, $fixture->vault, $archive, $fixture->path('header-export.sqlite'), offline: true); $source = null;
        $wire = RequiredFile::bytes(file_get_contents($archive . '/manifest.json'));
        $envelope = JsonRecord::decode($wire); $header = JsonRecord::checked($envelope['payload'] ?? null);
        database_transfer_header_case($fixture, $archive, $header, category: null);
        database_transfer_header_case($fixture, $archive, array_reverse($header, preserve_keys: true), category: null);
        $result = DatabaseTransferVerification::manifest($archive, $fixture->vault);
        $result['header']['columns'][0]['columns'][0]['name'] = 'caller-change';
        Assert::same(DatabaseTransferManifest::contract()[0]['columns'][0]['name'] ?? null, 'id');
        database_transfer_header_refusals($fixture, $archive, $header);
        database_transfer_header_numeric_object($fixture, $archive, $header);
        database_transfer_header_descriptor_oracles($fixture, $archive, $header);
        foreach ([' ' . $wire, $wire . "\n", str_replace('"payload":', replace: '"payload":null,"payload":', subject: $wire)] as $invalid) {
            database_transfer_header_refuse($fixture, $archive, $invalid, DatabaseFailure::class, category: 'schema');
        }
        database_transfer_header_refuse($fixture, $archive, wire: '{', exception: \JsonException::class, category: null);
        database_transfer_header_refuse($fixture, $archive, wire: 'null', exception: \RuntimeException::class, category: null);
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $source = null; $fixture->directory->close(); }
}

function database_transfer_header_refusals(TransferSqliteFixture $fixture, string $archive, array $header): void
{
    foreach ([null, false, 0, 0.0, -0.0, '', [], ['unexpected' => []]] as $columns) {
        $changed = $header; $changed['columns'] = $columns;
        database_transfer_header_case($fixture, $archive, $changed, category: 'schema');
    }
    foreach (['missing', 'extra', 'reordered', 'entry-order', 'column-order', 'nullable-integer'] as $fault) {
        $changed = $header; $columns = JsonRecord::checked($header['columns'] ?? null);
        $first = JsonRecord::checked($columns[0] ?? null); $fields = JsonRecord::checked($first['columns'] ?? null);
        $column = JsonRecord::checked($fields[0] ?? null);
        if ($fault === 'missing') { array_pop($columns); }
        if ($fault === 'extra') { $columns[] = $first; }
        if ($fault === 'reordered') { $columns = array_reverse($columns); }
        if ($fault === 'entry-order') { $columns[0] = array_reverse($first, preserve_keys: true); }
        if ($fault === 'column-order') { $fields[0] = array_reverse($column, preserve_keys: true); $first['columns'] = $fields; $columns[0] = $first; }
        if ($fault === 'nullable-integer') { $column['nullable'] = 0; $fields[0] = $column; $first['columns'] = $fields; $columns[0] = $first; }
        $changed['columns'] = $columns; $changed['verifier'] = 'invalid';
        database_transfer_header_case($fixture, $archive, $changed, category: 'schema');
    }
    $changed = $header; $changed['verifier'] = 'invalid'; $changed['engine'] = 'unsupported';
    database_transfer_header_case($fixture, $archive, $changed, category: 'schema', digestOverride: 'invalid');
    database_transfer_header_case($fixture, $archive, $changed, category: 'key');
    foreach (['columns', 'format', 'verifier', 'tables'] as $field) {
        $changed = $header; unset($changed[$field]); database_transfer_header_case($fixture, $archive, $changed, category: 'schema');
    }
}

/** Correct numeric lookups do not make a differently ordered JSON object into a list. */
function database_transfer_header_numeric_object(TransferSqliteFixture $fixture, string $archive, array $header): void
{
    $columns = JsonRecord::checked($header['columns'] ?? null);
    $header['columns'] = array_replace([1 => $columns[1] ?? throw new \RuntimeException('Missing second fixed table.'),
        0 => $columns[0] ?? throw new \RuntimeException('Missing first fixed table.')], $columns);
    Assert::same(array_keys($header['columns']), [1, 0, ...range(2, end: 15)]);
    database_transfer_header_case($fixture, $archive, $header, category: 'schema');
}

/** Native fallback values are deliberately outside the packed descriptor's supported shape. */
function database_transfer_header_descriptor_oracles(TransferSqliteFixture $fixture, string $archive, array $header): void
{
    $property = new \ReflectionProperty(DatabaseTransferManifest::class, 'dataTables');
    /** @var \Tablo\DatabaseTransferManifest::Cache $saved Checked finite cache initialized by the independent public contract assertions. */
    $saved = $property->getValue();
    try {
        foreach ([0, 0.0, -0.0] as $nullable) {
            $cache = $saved; $cache[1][0][1] = [['name' => 'id', 'type' => 'bigint', 'nullable' => $nullable]];
            $property->setValue(null, $cache);
            database_transfer_header_descriptor_case($fixture, $archive, $header, is_int($nullable) ? null : 'schema');
        }
        foreach ([['field' => ['name' => 'id', 'type' => 'bigint', 'nullable' => false]],
            [['nullable' => false, 'type' => 'bigint', 'name' => 'id']],
            [['name' => 1.25, 'type' => 'bigint', 'nullable' => false]]] as $columns) {
            $cache = $saved; $cache[1][0][1] = $columns; $property->setValue(null, $cache);
            database_transfer_header_descriptor_case($fixture, $archive, $header, category: null);
        }
        $cache = $saved; $cache[1][15][1] = [['name' => NAN, 'type' => 'bigint', 'nullable' => false]];
        $property->setValue(null, $cache); $header['format'] = 0; $header['columns'] = null;
        $wire = JsonEncoding::encode(['payload' => $header, 'sha256' => 'invalid']);
        database_transfer_header_refuse($fixture, $archive, $wire, \JsonException::class, category: null);
    } finally { $property->setValue(null, $saved); }
}

function database_transfer_header_descriptor_case(TransferSqliteFixture $fixture, string $archive, array $header, ?string $category): void
{
    $header['layout'] = DatabaseTransferManifest::layout(); $header['columns'] = DatabaseTransferManifest::contract();
    database_transfer_header_case($fixture, $archive, $header, $category);
}

function database_transfer_header_case(TransferSqliteFixture $fixture, string $archive, array $header, ?string $category, ?string $digestOverride = null): void
{
    $digest = hash('sha256', JsonEncoding::encode($header)); $before = $header;
    $wire = JsonEncoding::encode(['payload' => $header, 'sha256' => $digestOverride ?? $digest]);
    if ($category !== null) { database_transfer_header_refuse($fixture, $archive, $wire, DatabaseFailure::class, $category); return; }
    Assert::same(file_put_contents($archive . '/manifest.json', $wire), strlen($wire));
    $result = DatabaseTransferVerification::manifest($archive, $fixture->vault);
    Assert::same($result, ['header' => $header, 'digest' => $digest]); Assert::same($header, $before);
    Assert::same(RequiredFile::bytes(file_get_contents($archive . '/manifest.json')), $wire);
}

/** @param class-string<\Throwable> $exception */
function database_transfer_header_refuse(TransferSqliteFixture $fixture, string $archive, string $wire, string $exception, ?string $category): void
{
    Assert::same(file_put_contents($archive . '/manifest.json', $wire), strlen($wire));
    Assert::same(database_transfer_codec_failure(static fn (): array => DatabaseTransferVerification::manifest($archive, $fixture->vault)),
        ['exception' => $exception, 'category' => $category, 'previous' => null, 'output' => '']);
    Assert::same(RequiredFile::bytes(file_get_contents($archive . '/manifest.json')), $wire);
}

/** The unchanged public encoder is the independent byte oracle; this helper adds no test identity. */
function database_transfer_wire_byte_oracles(): void
{
    $bytes = implode('', array_map(chr(...), range(0, end: 255)));
    foreach ([[], [null, 0, PHP_INT_MIN, PHP_INT_MAX, ''], [$bytes, "\0\xff\xc0\x80"]] as $values) {
        database_transfer_wire_byte_case($values);
    }
    foreach ([1, 2, 3, 3071, 3072, 3073, 6143, 6144, 6145, 90_000] as $size) {
        database_transfer_wire_byte_case([null, 7, str_repeat("\xff", times: $size)]);
        database_transfer_wire_byte_case(['prefix', str_repeat('x', times: $size), PHP_INT_MIN]);
    }
    database_transfer_wire_byte_case([str_repeat("\xff", times: 3071), str_repeat("\0", times: 3073), '', null]);
    database_transfer_wire_byte_case([str_repeat("\xff", times: 90_000), str_repeat("\xff", times: 90_001)]);
    Assert::same(DatabaseTransferWire::completeLine([null, 0, '', "\xff"]),
        '[["null"],["integer","0"],["bytes",""],["bytes","\\/w=="]]' . "\n");
}

/** @param \Tablo\DatabaseTransferRows::Values $values */
function database_transfer_wire_byte_case(array $values): void
{
    $before = $values;
    $expected = JsonEncoding::encode(DatabaseTransferRows::encode($values)) . "\n";
    Assert::same(DatabaseTransferWire::completeLine($values), $expected);
    Assert::same($values, $before);
}

/** Consume the public encoder's exact wire and retain an independent caller alias. */
function database_transfer_codec_public_oracles(): void
{
    $table = database_transfer_codec_public_table();
    $bytes = implode('', array_map(chr(...), range(0, end: 255)));
    foreach ([null, '', 'ASCII punctuation !#$%&()*+,-.:;<=>?@[]^_`{|}~', $bytes, "\0\xff\xc0\x80", 'Café 🧪'] as $payload) {
        database_transfer_codec_value_case([null, PHP_INT_MIN, PHP_INT_MAX, $payload, 'Café 🧪'], $table);
    }
    foreach ([191, 192, 193, 383, 384, 385, 3071, 3072, 3073, 4095, 4096, 4097, 90_000] as $size) {
        database_transfer_codec_value_case([null, PHP_INT_MIN, PHP_INT_MAX, str_repeat('x', times: $size), null], $table);
    }
    database_transfer_codec_value_case([null, PHP_INT_MIN, PHP_INT_MAX, str_repeat("\xff", times: 90_000), ''], $table);
    $binary = ['name' => 'codec', 'columns' => [['name' => 'payload', 'type' => 'longblob', 'nullable' => true]], 'primary' => ['payload']];
    database_transfer_codec_wire_refusals($binary);
    database_transfer_codec_typed_refusals($binary);
    database_transfer_codec_syntax_precedes_semantics();
    database_transfer_codec_descriptor_fallback();
}

/** A malformed tail must not run validators that alter process-local parser state. */
function database_transfer_codec_syntax_precedes_semantics(): void
{
    $time = ['name' => 'time', 'columns' => [['name' => 'created_at', 'type' => 'varbinary(20)', 'nullable' => false]], 'primary' => []];
    \DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-31'); $before = \DateTimeImmutable::getLastErrors();
    $wire = JsonEncoding::encode([['bytes', base64_encode('2026-01-01T00:00:00Z')]]) . 'x';
    $line = $wire; $alias = $line;
    $failure = database_transfer_codec_failure(static function () use (&$line, $time): array { return DatabaseTransferRows::consumeLine($line, $time); });
    $after = \DateTimeImmutable::getLastErrors();
    Assert::true($before !== false && $before['warning_count'] > 0); Assert::same($after, $before);
    Assert::same($failure['exception'], \JsonException::class); Assert::same($line, $wire); Assert::same($alias, $wire);
    foreach (['longtext' => '[["bytes","gA=="]]x', 'bigint' => '[["integer","01"]]x'] as $type => $malformed) {
        $table = ['name' => 'state', 'columns' => [['name' => 'value', 'type' => $type, 'nullable' => false]], 'primary' => []];
        preg_match('//u', $type === 'bigint' ? "\x80" : ''); $beforeRegex = preg_last_error(); $line = $malformed;
        $failure = database_transfer_codec_failure(static function () use (&$line, $table): array { return DatabaseTransferRows::consumeLine($line, $table); });
        $afterRegex = preg_last_error();
        Assert::same($afterRegex, $beforeRegex); Assert::same($failure['exception'], \JsonException::class); Assert::same($line, $malformed);
    }
    $table = ['name' => 'json-state', 'columns' => [['name' => 'value', 'type' => 'longblob', 'nullable' => true]], 'primary' => []];
    json_decode('{'); $beforeJson = json_last_error(); $line = '[["bytes","QQ=="]]';
    $value = DatabaseTransferRows::consumeLine($line, $table); $afterJson = json_last_error();
    Assert::same($beforeJson, JSON_ERROR_SYNTAX); Assert::same($afterJson, $beforeJson); Assert::same($value, ['A']); Assert::same($line, '');
    json_decode('null');
}

/** Unsupported metadata reaches the original JSON-first decoder even under strict warnings. */
function database_transfer_codec_descriptor_fallback(): void
{
    set_error_handler(static function (int $severity, string $message): never { throw new \ErrorException($message, code: 0, severity: $severity); });
    try {
        foreach ([['name' => 'value', 'type' => 'varbinary(999999999999999999999999)', 'nullable' => true],
            ['name' => 'value', 'type' => 'longblob', 'nullable' => 0], ['name' => 'value', 'type' => null, 'nullable' => true]] as $column) {
            $table = ['name' => 'fallback', 'columns' => [$column], 'primary' => []]; $wire = '[["bytes",""]]junk';
            database_transfer_codec_unknown_columns_case($wire, $table);
        }
        $table = ['name' => 'fallback', 'columns' => ['value' => ['name' => 'value', 'type' => 'longblob', 'nullable' => true]], 'primary' => []];
        $wire = '[["bytes","QQ=="]]junk'; database_transfer_codec_unknown_columns_case($wire, $table);
        $table = ['name' => 'depth', 'columns' => [['name' => 'value', 'type' => 'longblob', 'nullable' => true]], 'primary' => []];
        $wire = str_repeat('[', times: 17) . '0' . str_repeat(']', times: 17);
        database_transfer_codec_failure_case($wire, $table, [\JsonException::class, null, $wire]);
    } finally { restore_error_handler(); }
}

/** Reflection deliberately supplies unsupported native metadata to the public array parameters. */
function database_transfer_codec_unknown_columns_case(string $wire, array $table): void
{
    $reference = new \ReflectionMethod(DatabaseTransferRows::class, 'line'); $consume = new \ReflectionMethod(DatabaseTransferRows::class, 'consumeLine');
    $expected = database_transfer_codec_failure(static fn (): array => JsonRecord::checked($reference->invoke(null, $wire, $table)));
    Assert::same($expected['exception'], \JsonException::class);
    $line = $wire; $alias = $line; $arguments = [&$line, $table];
    Assert::same(database_transfer_codec_failure(static fn (): array => JsonRecord::checked($consume->invokeArgs(null, $arguments))), $expected);
    Assert::same($line, $wire); Assert::same($alias, $wire);
}

/** @return \Tablo\DatabaseTransferManifest::Table */
function database_transfer_codec_public_table(): array
{
    return ['name' => 'codec', 'columns' => [
        ['name' => 'optional', 'type' => 'bigint', 'nullable' => true],
        ['name' => 'minimum', 'type' => 'bigint', 'nullable' => false],
        ['name' => 'maximum', 'type' => 'bigint', 'nullable' => false],
        ['name' => 'payload', 'type' => 'longblob', 'nullable' => true],
        ['name' => 'note', 'type' => 'longtext', 'nullable' => true],
    ], 'primary' => ['minimum']];
}

/** @param \Tablo\DatabaseTransferRows::Values $values
 * @param \Tablo\DatabaseTransferManifest::Table $table */
function database_transfer_codec_value_case(array $values, array $table): void
{
    $before = $values;
    $wire = JsonEncoding::encode(DatabaseTransferRows::encode($values));
    $line = $wire; $alias = $line;
    Assert::same(DatabaseTransferRows::line($wire, $table), $values);
    Assert::same(DatabaseTransferRows::consumeLine($line, $table), $values);
    Assert::same($line, ''); Assert::same($alias, $wire); Assert::same($values, $before);
    Assert::same(DatabaseTransferWire::completeLine($values), $wire . "\n");
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function database_transfer_codec_wire_refusals(array $table): void
{
    foreach (['[["b\u0079tes","QQ=="]]', '[["bytes","\u0051Q=="]]', '[["bytes","/w=="]]',
        ' [["bytes","QQ=="]]', '[["bytes","QQ=="]] ', '{"0":["bytes","QQ=="]}',
        '[{"0":"bytes","1":"QQ=="}]'] as $wire) {
        database_transfer_codec_failure_case($wire, $table, [DatabaseFailure::class, 'schema', '']);
    }
    foreach (["\n", "\x7f", 'é', '"', '\\'] as $payload) {
        database_transfer_codec_failure_case(JsonEncoding::encode([['bytes', $payload]]), $table, [DatabaseFailure::class, 'schema', '']);
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table */
function database_transfer_codec_typed_refusals(array $table): void
{
    foreach (['[["bytes","!"]]', '[["bytes","QQ"]]', '[["unknown","QQ=="]]',
        '[["bytes","AB=="]]', '[["bytes","AAB="]]', '[["bytes","' . str_repeat('A', times: 254) . '==AAAA"]]',
        '[["integer","9223372036854775808"]]', '[["integer","01"]]', '[["integer","0"]]'] as $wire) {
        database_transfer_codec_failure_case($wire, $table, [DatabaseFailure::class, 'schema', '']);
    }
    foreach (['[[]]', '[null]', '["bytes"]', '[["bytes"]]', '[["bytes",7]]'] as $wire) {
        database_transfer_codec_failure_case($wire, $table, [\RuntimeException::class, null, '']);
    }
    foreach (['null', 'false'] as $wire) {
        database_transfer_codec_failure_case($wire, $table, [\RuntimeException::class, null, $wire]);
    }
    foreach (['[', '[["bytes","' . "\xff" . '"]]'] as $wire) {
        database_transfer_codec_failure_case($wire, $table, [\JsonException::class, null, $wire]);
    }
}

/** @param \Tablo\DatabaseTransferManifest::Table $table
 * @param array{class-string<\Throwable>, ?string, string} $expected */
function database_transfer_codec_failure_case(string $wire, array $table, array $expected): void
{
    $reference = database_transfer_codec_failure(static fn (): array => DatabaseTransferRows::line($wire, $table));
    Assert::same($reference, ['exception' => $expected[0], 'category' => $expected[1], 'previous' => null, 'output' => '']);
    $line = $wire; $alias = $line;
    $actual = database_transfer_codec_failure(static function () use (&$line, $table): array {
        return DatabaseTransferRows::consumeLine($line, $table);
    });
    Assert::same($actual, $reference); Assert::same($line, $expected[2]); Assert::same($alias, $wire);
}

/** @param \Closure(): array $read
 * @return array{exception: class-string<\Throwable>|null, category: ?string, previous: class-string<\Throwable>|null, output: string} */
function database_transfer_codec_failure(\Closure $read): array
{
    $caught = null; ob_start();
    try { $read(); } catch (\Throwable $error) { $caught = $error; }
    finally { $output = ob_get_clean(); }
    if ($output === false) { throw new \RuntimeException('Codec output capture unavailable.'); }
    $previous = $caught?->getPrevious();
    return ['exception' => $caught === null ? null : $caught::class,
        'category' => $caught instanceof DatabaseFailure ? $caught->category : null,
        'previous' => $previous === null ? null : $previous::class, 'output' => $output];
}
