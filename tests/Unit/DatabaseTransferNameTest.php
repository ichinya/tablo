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
                catch (DatabaseFailure $error) { $caught = $error; }
                Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
                Assert::same(ServerSchema::rows($source, 'SELECT CAST(name AS BLOB) AS bytes,typeof(name) AS storage_type FROM git_tokens'), $before);
                Assert::false($source->inTransaction()); $source = null;
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
                Assert::same(DatabaseTransferVerification::manifest($directory, $fixture->vault)['header']['logicalVersion'], 10);
                $target->arm(); $caught = null;
                try { DatabaseTransfer::importServer($target, $fixture->vault, $directory, $fixture->path('import-' . $index . '.sqlite'), offline: true); }
                catch (DatabaseFailure $error) { $caught = $error; }
                $target->disarm();
                Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
                Assert::same($target->targetCalls, 0);
                Assert::same(TransferSqliteFixture::snapshot($target), $before);
                Assert::false($target->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($target));
            }
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $target = null; $source = null; $fixture->directory->close(); }
    }
}

/** Public wire, shared-header ownership and mutations stay independent of cache storage. */
function database_transfer_manifest_public_contract(): void
{
    $layout = '58a779e680f9479d0b41a89fbcc72683d80608f12addef16b029b48ee2b1d4e8';
    $contractDigest = '536b4f80f2bea4ca8216bf5473a8200e1dd19621f4996c1ff5415ee3a7960b32';
    $tables = \Tablo\DatabaseTransferManifest::tables();
    Assert::same(count($tables), 15);
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
