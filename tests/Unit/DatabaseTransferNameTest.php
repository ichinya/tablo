<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\DatabaseTransferRows;
use Tablo\DatabaseTransferWire;
use Tablo\JsonEncoding;
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
    foreach ([3071, 3072, 3073, 4095, 4096, 4097, 90_000] as $size) {
        database_transfer_codec_value_case([null, PHP_INT_MIN, PHP_INT_MAX, str_repeat('x', times: $size), null], $table);
    }
    database_transfer_codec_value_case([null, PHP_INT_MIN, PHP_INT_MAX, str_repeat("\xff", times: 90_000), ''], $table);
    $binary = ['name' => 'codec', 'columns' => [['name' => 'payload', 'type' => 'longblob', 'nullable' => true]], 'primary' => ['payload']];
    database_transfer_codec_wire_refusals($binary);
    database_transfer_codec_typed_refusals($binary);
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
