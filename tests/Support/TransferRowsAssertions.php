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
            foreach ([2, 3] as $ordinal) {
                foreach (['pull', 'visit'] as $mode) { transfer_rows_assertions_failure($fault, $mode, $ordinal); }
            }
        }
        foreach (['pull', 'visit'] as $mode) {
            transfer_rows_assertions_failure('prepare', $mode, ordinal: 2);
            foreach ([2, 3] as $ordinal) { transfer_rows_assertions_failure('bind', $mode, $ordinal); }
        }
        transfer_rows_assertions_archive(); transfer_rows_assertions_composite();
    }
}

function transfer_rows_assertions_equivalent(): void
{
    $expected = transfer_rows_assertions_values(); $table = transfer_rows_assertions_table();
    $pull = transfer_rows_assertions_source(); $visit = transfer_rows_assertions_source(); $rows = [];
    Assert::same(iterator_to_array(DatabaseTransferRows::read($pull, $table), preserve_keys: false), $expected);
    DatabaseTransferRows::visit($visit, $table, static function (array $values) use ($visit, &$rows): void {
        transfer_rows_assertions_boundary($visit); $rows[] = $values;
    });
    Assert::same($rows, $expected);
    transfer_rows_assertions_lifecycle($pull, prepared: 2, executed: 4, closed: 4);
    transfer_rows_assertions_lifecycle($visit, prepared: 2, executed: 4, closed: 4);
    $empty = new TransferRowsPdo(); $emptyRows = [];
    DatabaseTransferRows::visit($empty, $table, static function (array $values) use (&$emptyRows): void { $emptyRows[] = $values; });
    Assert::same($emptyRows, []); transfer_rows_assertions_lifecycle($empty, prepared: 1, executed: 1, closed: 1);
    $one = transfer_rows_assertions_source(); $one->exec('DELETE FROM sites WHERE id > 1');
    Assert::same(iterator_to_array(DatabaseTransferRows::read($one, $table), preserve_keys: false), [$expected[0]]);
    transfer_rows_assertions_lifecycle($one, prepared: 2, executed: 2, closed: 2);
    $receipt = transfer_rows_assertions_receipt(); $verified = transfer_rows_assertions_source();
    DatabaseTransferVerification::table($verified, $table, $receipt);
    transfer_rows_assertions_lifecycle($verified, prepared: 2, executed: 4, closed: 4);
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
    foreach ([1, 2, 3] as $prefix) {
        $source = transfer_rows_assertions_source(); $reader = DatabaseTransferRows::read($source, transfer_rows_assertions_table());
        transfer_rows_assertions_lifecycle($source, prepared: 0, executed: 0, closed: 0);
        for ($index = 0; $index < $prefix; $index++) {
            if ($index !== 0) { $reader->next(); }
            Assert::same($reader->current(), transfer_rows_assertions_values()[$index] ?? throw new \RuntimeException('Partial row oracle absent.'));
            transfer_rows_assertions_boundary($source);
        }
        transfer_rows_assertions_lifecycle($source, prepared: min(2, $prefix), executed: $prefix, closed: $prefix, events: ['live' => 1]);
        unset($reader); transfer_rows_assertions_lifecycle($source, prepared: min(2, $prefix), executed: $prefix, closed: $prefix);
        Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT COUNT(*) FROM sites'), 3);
    }
}

function transfer_rows_assertions_failure(string $fault, string $mode, int $ordinal): void
{
    $source = transfer_rows_assertions_source(); $source->fault = $fault; $source->faultAt = $ordinal;
    if ($fault === 'invalid') { $source->exec("UPDATE sites SET last_error = CAST(X'FF' AS TEXT) WHERE id = " . $ordinal); }
    Assert::true($source->beginTransaction()); $source->exec('UPDATE sites SET open_issues = 7 WHERE id = 3');
    $seen = []; $primary = new \LogicException('Synthetic consumer refusal.'); $caught = null;
    $consume = static function (array $values) use ($source, $fault, $ordinal, &$seen, $primary): void {
        transfer_rows_assertions_boundary($source);
        $id = \Tablo\SqlColumn::integer($values, key: '0');
        if ($fault === 'consumer' && $id === $ordinal) { throw $primary; }
        $seen[] = $id;
    };
    try {
        if ($mode === 'visit') { DatabaseTransferRows::visit($source, transfer_rows_assertions_table(), $consume); }
        if ($mode === 'pull') { foreach (DatabaseTransferRows::read($source, transfer_rows_assertions_table()) as $values) { $consume($values); } }
    } catch (\Throwable $error) { $caught = $error; }
    Assert::same($seen, range(1, $ordinal - 1));
    Assert::true($source->inTransaction()); Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT open_issues FROM sites WHERE id = 3'), 7);
    Assert::true($source->rollBack()); Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT open_issues FROM sites WHERE id = 3'), null);
    transfer_rows_assertions_failure_type($fault, $caught, $primary);
    unset($caught, $error, $consume);
    $executed = match ($fault) { 'prepare' => 1, 'bind' => $ordinal - 1, default => $ordinal };
    transfer_rows_assertions_lifecycle($source, prepared: $fault === 'prepare' ? 1 : 2, executed: $executed,
        closed: $fault === 'prepare' ? 1 : $ordinal, events: [
            'prepareAttempts' => 2, 'succeeded' => $fault === 'execute' ? $executed - 1 : $executed,
            'fetched' => $fault === 'execute' ? $executed - 1 : $executed,
        ]);
}

/** Row attempts include a bind refusal; execution attempts do not. Every attempt closes once.
 * @param array{live?: int, prepareAttempts?: int, succeeded?: int, fetched?: int} $events */
function transfer_rows_assertions_lifecycle(TransferRowsPdo $source, int $prepared, int $executed, int $closed,
    array $events = []): void
{
    Assert::same([$source->counts['prepareAttempts'], $source->counts['prepared']], [$events['prepareAttempts'] ?? $prepared, $prepared]);
    Assert::same([$source->counts['attempted'], $source->counts['closeAttempts'], $source->counts['closed']], [$closed, $closed, $closed]);
    Assert::same([$source->counts['executed'], $source->counts['succeeded'], $source->counts['fetched']],
        [$executed, $events['succeeded'] ?? $executed, $events['fetched'] ?? $executed]);
    Assert::same([$source->counts['open'], $source->counts['live']], [0, $events['live'] ?? 0]);
}

function transfer_rows_assertions_boundary(TransferRowsPdo $source): void
{
    Assert::same([$source->counts['attempted'], $source->counts['closeAttempts'], $source->counts['closed'], $source->counts['open']],
        [$source->counts['attempted'], $source->counts['attempted'], $source->counts['attempted'], 0]);
    Assert::same(transfer_rows_assertions_scalar($source, sql: 'SELECT COUNT(*) FROM sites'), 3);
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
        Assert::same($receipt, transfer_rows_assertions_receipt()); transfer_rows_assertions_lifecycle($source, prepared: 2, executed: 4, closed: 4);
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
    Assert::instanceOf($caught, \PDOException::class);
    transfer_rows_assertions_lifecycle($source, prepared: 2, executed: 2, closed: 2);
    Assert::same(iterator_to_array(DatabaseTransferRows::read($scratch, transfer_rows_assertions_table()), preserve_keys: false), [transfer_rows_assertions_values()[0]]);
    $file = $path . '/sites.0.jsonl'; Assert::same(file_get_contents($file), DatabaseTransferWire::completeLine(transfer_rows_assertions_values()[0]));
    // Windows refuses unlink while a handle remains open, so this also checks the partial-file finally owner.
    Assert::true(unlink($file));
    $source = transfer_rows_assertions_source(); $caught = null;
    try { DatabaseTransferArchive::measure($source, new TransferRowsPdo(), transfer_rows_assertions_table(), remainingChunks: 0); }
    catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'schema'); transfer_rows_assertions_lifecycle($source, prepared: 1, executed: 1, closed: 1);
}

/** Explicit binary key order and the public tagged encoder are independent of the reader cursor. */
function transfer_rows_assertions_composite(): void
{
    $table = transfer_rows_assertions_composite_table(); $expected = transfer_rows_assertions_composite_values();
    $source = transfer_rows_assertions_source();
    $source->exec('CREATE TABLE composite_cells (binary_key TEXT NOT NULL,integer_key INTEGER NOT NULL,payload TEXT,note TEXT,PRIMARY KEY(binary_key,integer_key)) WITHOUT ROWID');
    foreach (array_reverse($expected) as $values) { DatabaseTransferRows::insert($source, $table, $values); }
    $wire = '';
    foreach ($expected as $values) { $wire .= \Tablo\JsonEncoding::encode(DatabaseTransferRows::encode($values)) . "\n"; }
    Assert::same(iterator_to_array(DatabaseTransferRows::read($source, $table), preserve_keys: false), $expected);
    transfer_rows_assertions_lifecycle($source, prepared: 2, executed: 5, closed: 5);
    $rows = []; $actualWire = '';
    DatabaseTransferRows::visit($source, $table, static function (array $values) use ($source, &$rows, &$actualWire): void {
        transfer_rows_assertions_boundary($source); $rows[] = $values; $actualWire .= DatabaseTransferWire::completeLine($values);
    });
    Assert::same($rows, $expected); Assert::same($actualWire, $wire); Assert::same(hash('sha256', $actualWire), hash('sha256', $wire));
    transfer_rows_assertions_lifecycle($source, prepared: 4, executed: 10, closed: 10);
}

/** @return \Tablo\DatabaseTransferManifest::Table */
function transfer_rows_assertions_composite_table(): array
{
    return ['name' => 'composite_cells', 'columns' => [
        ['name' => 'binary_key', 'type' => 'varbinary(8)', 'nullable' => false],
        ['name' => 'integer_key', 'type' => 'bigint', 'nullable' => false],
        ['name' => 'payload', 'type' => 'longblob', 'nullable' => true],
        ['name' => 'note', 'type' => 'longtext', 'nullable' => true],
    ], 'primary' => ['binary_key', 'integer_key']];
}

/** @return list<\Tablo\DatabaseTransferRows::Values> */
function transfer_rows_assertions_composite_values(): array
{
    return [["\0", PHP_INT_MIN, "\0\xff", 'Сайт 🧪'], ["\0", PHP_INT_MAX, null, ''],
        ["\0a", 0, '', 'Composite UTF-8'], ["\xff", 1, 'binary', null]];
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
