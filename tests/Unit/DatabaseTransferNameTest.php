<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\DatabaseTransferRows;
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
