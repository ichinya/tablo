<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransferArchive;
use Tablo\DatabaseTransferRows;
use Tablo\DatabaseTransferVerification;
use Tablo\DatabaseTransferWire;
use Testo\Assert;

/** Independent typed rows and real cursor faults exercise both reader APIs under existing identities. */
final class TransferRowsAssertions
{
    public static function run(): void
    {
        transfer_rows_assertions_equivalent(); transfer_rows_assertions_partial();
        foreach (['execute', 'fetch', 'close', 'invalid', 'consumer'] as $fault) {
            foreach (['pull', 'visit'] as $mode) { transfer_rows_assertions_failure($fault, $mode); }
        }
        transfer_rows_assertions_archive();
    }
}

function transfer_rows_assertions_equivalent(): void
{
    $expected = transfer_rows_assertions_values(); $table = transfer_rows_assertions_table();
    $pull = transfer_rows_assertions_source(); $visit = transfer_rows_assertions_source(); $rows = [];
    Assert::same(iterator_to_array(DatabaseTransferRows::read($pull, $table), preserve_keys: false), $expected);
    DatabaseTransferRows::visit($visit, $table, static function (array $values) use (&$rows): void { $rows[] = $values; });
    Assert::same($rows, $expected);
    Assert::same([$pull->prepared, $pull->closed, $visit->prepared, $visit->closed], [4, 4, 4, 4]);
    $empty = new TransferRowsPdo(); $emptyRows = [];
    DatabaseTransferRows::visit($empty, $table, static function (array $values) use (&$emptyRows): void { $emptyRows[] = $values; });
    Assert::same($emptyRows, []); Assert::same([$empty->prepared, $empty->closed], [1, 1]);
    $receipt = transfer_rows_assertions_receipt(); $verified = transfer_rows_assertions_source();
    DatabaseTransferVerification::table($verified, $table, $receipt);
    Assert::same([$verified->prepared, $verified->closed], [4, 4]);
    foreach (['rows', 'sha256'] as $field) {
        $bad = $receipt; $caught = null;
        if ($field === 'rows') { $bad['rows'] = 2; }
        if ($field === 'sha256') { $bad['sha256'] = str_repeat('0', times: 64); }
        try { DatabaseTransferVerification::table(transfer_rows_assertions_source(), $table, $bad); }
        catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    }
}

function transfer_rows_assertions_partial(): void
{
    $source = transfer_rows_assertions_source(); $reader = DatabaseTransferRows::read($source, transfer_rows_assertions_table());
    Assert::same($reader->current(), transfer_rows_assertions_values()[0]); Assert::same([$source->prepared, $source->closed], [1, 1]);
    $reader->next(); Assert::same($reader->current(), transfer_rows_assertions_values()[1]);
    Assert::same([$source->prepared, $source->closed], [2, 2]);
    unset($reader); Assert::same([$source->prepared, $source->closed], [2, 2]);
    Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT COUNT(*) FROM sites'), 3);
}

function transfer_rows_assertions_failure(string $fault, string $mode): void
{
    $source = transfer_rows_assertions_source(); $source->fault = $fault;
    if ($fault === 'invalid') { $source->exec("UPDATE sites SET last_error = CAST(X'FF' AS TEXT) WHERE id = 2"); }
    Assert::true($source->beginTransaction()); $source->exec('UPDATE sites SET open_issues = 7 WHERE id = 3');
    $seen = []; $primary = new \LogicException('Synthetic consumer refusal.'); $caught = null;
    $consume = static function (array $values) use ($source, $fault, &$seen, $primary): void {
        Assert::same($source->closed, $source->prepared);
        $id = \Tablo\SqlColumn::integer($values, key: '0');
        if ($fault === 'consumer' && $id === 2) { throw $primary; }
        $seen[] = $id;
    };
    try {
        if ($mode === 'visit') { DatabaseTransferRows::visit($source, transfer_rows_assertions_table(), $consume); }
        if ($mode === 'pull') { foreach (DatabaseTransferRows::read($source, transfer_rows_assertions_table()) as $values) { $consume($values); } }
    } catch (\Throwable $error) { $caught = $error; }
    Assert::same($seen, [1]); Assert::same([$source->prepared, $source->closed], [2, 2]);
    Assert::true($source->inTransaction()); Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT open_issues FROM sites WHERE id = 3'), 7);
    Assert::true($source->rollBack()); Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT open_issues FROM sites WHERE id = 3'), null);
    transfer_rows_assertions_failure_type($fault, $caught, $primary);
}

function transfer_rows_assertions_failure_type(string $fault, ?\Throwable $caught, \LogicException $primary): void
{
    if ($fault === 'consumer') { Assert::same($caught, $primary); return; }
    if ($fault === 'invalid') {
        Assert::instanceOf($caught, DatabaseFailure::class);
        Assert::same($caught->category, 'schema'); Assert::same($caught->getPrevious(), null); return;
    }
    Assert::instanceOf($caught, \PDOException::class);
    Assert::same($caught?->getMessage(), 'Synthetic transfer ' . $fault . ' failure.');
    Assert::same($caught?->getPrevious(), null);
}

function transfer_rows_assertions_archive(): void
{
    $directory = new TemporaryDirectory('tablo-transfer-rows-');
    try {
        $source = transfer_rows_assertions_source(); $scratch = new TransferRowsPdo();
        $receipt = DatabaseTransferArchive::write($source, $scratch, transfer_rows_assertions_table(), $directory->path);
        Assert::same($receipt, transfer_rows_assertions_receipt()); Assert::same([$source->prepared, $source->closed], [4, 4]);
        Assert::same(iterator_to_array(DatabaseTransferRows::read($scratch, transfer_rows_assertions_table()), preserve_keys: false), transfer_rows_assertions_values());
        $measured = DatabaseTransferArchive::measure(transfer_rows_assertions_source(), new TransferRowsPdo(), transfer_rows_assertions_table(), remainingChunks: 1);
        Assert::same($measured, $receipt);
        transfer_rows_assertions_archive_failure($directory);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

function transfer_rows_assertions_archive_failure(TemporaryDirectory $directory): void
{
    $path = $directory->path . '/partial'; Assert::true(mkdir($path));
    $source = transfer_rows_assertions_source(); $source->fault = 'fetch'; $scratch = new TransferRowsPdo(); $caught = null;
    try { DatabaseTransferArchive::write($source, $scratch, transfer_rows_assertions_table(), $path); }
    catch (\PDOException $error) { $caught = $error; }
    Assert::instanceOf($caught, \PDOException::class); Assert::same([$source->prepared, $source->closed], [2, 2]);
    Assert::same(iterator_to_array(DatabaseTransferRows::read($scratch, transfer_rows_assertions_table()), preserve_keys: false), [transfer_rows_assertions_values()[0]]);
    $file = $path . '/sites.0.jsonl'; Assert::same(file_get_contents($file), DatabaseTransferWire::completeLine(transfer_rows_assertions_values()[0]));
    // Windows refuses unlink while a handle remains open, so this also checks the partial-file finally owner.
    Assert::true(unlink($file));
    $source = transfer_rows_assertions_source(); $caught = null;
    try { DatabaseTransferArchive::measure($source, new TransferRowsPdo(), transfer_rows_assertions_table(), remainingChunks: 0); }
    catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema'); Assert::same([$source->prepared, $source->closed], [1, 1]);
}

function transfer_rows_assertions_source(): TransferRowsPdo
{
    $source = new TransferRowsPdo();
    foreach (transfer_rows_assertions_values() as $values) { DatabaseTransferRows::insert($source, transfer_rows_assertions_table(), $values); }
    return $source;
}

/** @return \Tablo\DatabaseTransferManifest::Table */
function transfer_rows_assertions_table(): array
{
    return ['name' => 'sites', 'columns' => [
        ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
        ['name' => 'open_issues', 'type' => 'bigint', 'nullable' => true],
        ['name' => 'endpoint_token', 'type' => 'longblob', 'nullable' => true],
        ['name' => 'last_error', 'type' => 'longtext', 'nullable' => true],
        ['name' => 'checked_at', 'type' => 'varbinary(20)', 'nullable' => false],
    ], 'primary' => ['id']];
}

/** @return array{\Tablo\DatabaseTransferRows::Values, \Tablo\DatabaseTransferRows::Values, \Tablo\DatabaseTransferRows::Values} */
function transfer_rows_assertions_values(): array
{
    return [[1, PHP_INT_MIN, "\0\xff", 'Сайт 🧪', '0001-01-01T00:00:00Z'],
        [2, PHP_INT_MAX, null, '', '0099-12-31T23:59:59Z'], [3, null, 'binary', null, '0100-01-01T00:00:00Z']];
}

function transfer_rows_assertions_scalar(\PDO $db, string $sql): mixed
{
    $statement = \Tablo\SqlStatement::checked($db->query($sql));
    try { return $statement->fetchColumn(); }
    finally { $statement->closeCursor(); }
}

/** The original public encoder supplies the independent wire, including null/integer/bytes tags.
 * @return \Tablo\DatabaseTransferArchive::Receipt */
function transfer_rows_assertions_receipt(): array
{
    $wire = '';
    foreach (transfer_rows_assertions_values() as $values) { $wire .= \Tablo\JsonEncoding::encode(DatabaseTransferRows::encode($values)) . "\n"; }
    return ['name' => 'sites', 'rows' => 3, 'sha256' => hash('sha256', $wire),
        'chunks' => [['rows' => 3, 'bytes' => strlen($wire), 'sha256' => hash('sha256', $wire)]]];
}
