<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use PDOException;
use RuntimeException;
use Tablo\SqlStatement;
use Testo\Assert;
use Testo\Test;

final class SqlStatementTest
{
    #[Test]
    public function falseAcquisitionRefusesWithFixedPrivateDiagnostic(): void
    {
        $db = new FalseStatementPdo();
        $failure = null;
        try { SqlStatement::checked($db->prepare('private SQL marker')); }
        catch (RuntimeException $error) { $failure = $error; }
        Assert::true($failure instanceof RuntimeException);
        Assert::same(\Tablo\Tests\Support\FixtureValue::object($failure)->getMessage(), 'Cannot acquire database statement.');
    }

    #[Test]
    public function nativeExceptionIdentityAndCallerTransactionRemainOwned(): void
    {
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE sample (value INTEGER)');
        $db->beginTransaction();
        $statement = $db->prepare('INSERT INTO sample VALUES (?)', [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        Assert::true($statement !== false);
        Assert::same(SqlStatement::checked($statement), $statement);
        $statement = SqlStatement::checked($statement);
        try { $statement->execute([0]); } finally { $statement->closeCursor(); }
        Assert::true($db->inTransaction());
        $row = SqlStatement::checked($db->query('SELECT value FROM sample', PDO::FETCH_ASSOC));
        try {
            Assert::same($row->fetch(), ['value' => 0]);
            Assert::same($row->fetch(), false);
        } finally { $row->closeCursor(); }
        $db->rollBack();
        Assert::false($db->inTransaction());
        $row = SqlStatement::checked($db->query('SELECT COUNT(*) FROM sample'));
        try { Assert::same($row->fetchColumn(), 0); } finally { $row->closeCursor(); }

        $expected = new PDOException('Native identity control');
        $throwing = new ThrowingStatementPdo($expected);
        $caught = null;
        try { SqlStatement::checked($throwing->query('private SQL marker')); }
        catch (PDOException $error) { $caught = $error; }
        Assert::same($caught, $expected);
    }
}
