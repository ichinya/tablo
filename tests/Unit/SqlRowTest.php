<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\RequiredFile;
use Tablo\SqlColumn;
use Tablo\SqlRows;
use Testo\Assert;
use Testo\Test;

final class SqlRowTest
{
    #[Test]
    public function nullZeroAndEmptyBytesRetainTheirDistinctValues(): void
    {
        Assert::same(SqlColumn::integer(['value' => 0], 'value'), 0);
        Assert::same(SqlColumn::nullableInteger(['value' => null], 'value'), null);
        Assert::same(SqlColumn::nullableString(['value' => null], 'value'), null);
        Assert::same(SqlColumn::string(['value' => ''], 'value'), '');
        Assert::same(RequiredFile::bytes(''), '');
    }

    #[Test]
    public function absentAndWrongTypesRefuseWithPrivateFixedMessages(): void
    {
        foreach ([static fn () => SqlColumn::nullableInteger([], 'private-column'),
            static fn () => SqlColumn::nullableString([], 'private-column'),
            static fn () => SqlColumn::integer(['value' => 'private-marker'], 'value'),
            static fn () => SqlColumn::string(['value' => 0], 'value')] as $call) {
            $message = null;
            try { $call(); } catch (RuntimeException $error) { $message = $error->getMessage(); }
            Assert::same($message, 'Invalid database row.');
        }
        $message = null;
        try { RequiredFile::bytes(false); } catch (RuntimeException $error) { $message = $error->getMessage(); }
        Assert::same($message, 'Cannot read required file.');
    }

    #[Test]
    public function decoderPreservesEmptyFetchBatchAndActualColumnValues(): void
    {
        $db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE sample (number INTEGER NOT NULL, label TEXT)');
        $decode = static fn (array $row): array => ['number' => SqlColumn::integer($row, 'number'),
            'label' => SqlColumn::nullableString($row, 'label')];
        $query = \Tablo\SqlStatement::checked($db->query('SELECT * FROM sample'));
        try { Assert::same(SqlRows::one($query->fetch(), $decode), false); }
        finally { $query->closeCursor(); }
        $query = \Tablo\SqlStatement::checked($db->query('SELECT * FROM sample'));
        try { Assert::same(SqlRows::all($query->fetchAll(), $decode), []); }
        finally { $query->closeCursor(); }
        $db->exec("INSERT INTO sample VALUES (0, NULL), (1, '')");
        $query = \Tablo\SqlStatement::checked($db->query('SELECT * FROM sample ORDER BY number'));
        try { Assert::same(SqlRows::all($query->fetchAll(), $decode), [['number' => 0, 'label' => null], ['number' => 1, 'label' => '']]); }
        finally { $query->closeCursor(); }
    }
}
