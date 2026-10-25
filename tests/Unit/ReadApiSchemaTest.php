<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ReadApiConnection;
use Tablo\ReadApiSchemaAdmission;
use Tablo\ReadApiTokenSchema;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class ReadApiSchemaTest
{
    #[Test]
    public function necessaryMainShapeAdmitsCurrentTablesAndAdditiveSecretColumnsButRejectsViewsAndMissingColumns(): void
    {
        $db = A::database(); ReadApiSchemaAdmission::authentication($db); ReadApiSchemaAdmission::sites($db);
        $db->exec("ALTER TABLE sites ADD COLUMN future_secret TEXT DEFAULT 'future-canary'"); ReadApiSchemaAdmission::sites($db);
        $db->exec('ALTER TABLE sites RENAME TO hidden_sites; CREATE VIEW sites AS SELECT * FROM hidden_sites');
        A::reject(static fn () => ReadApiSchemaAdmission::sites($db)); $db = null;
        $db = A::database(); $db->exec('ALTER TABLE read_api_token RENAME COLUMN secret_hash TO damaged');
        A::reject(static fn () => ReadApiSchemaAdmission::authentication($db)); $db = null;
    }

    #[Test]
    public function existingConnectionNeverCreatesAbsentFileAndImmediatelyEnforcesQueryOnly(): void
    {
        $directory = new TemporaryDirectory('tablo-read-api-schema-');
        $reader = null; $writer = null;
        try {
            $path = $directory->path . '/absent.sqlite'; A::reject(static fn () => ReadApiConnection::open($path)); Assert::false(is_file($path));
            foreach ([':memory:', 'file::memory:', 'file:' . $path . '?mode=rwc'] as $invalid) { A::reject(static fn () => ReadApiConnection::open($invalid)); }
            $writer = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); \Tablo\Database::migrate($writer); A::administrator($writer);
            $before = A::snapshot($writer); $reader = ReadApiConnection::open($path);
            ReadApiSchemaAdmission::authentication($reader); ReadApiSchemaAdmission::sites($reader);
            A::reject(static fn () => $reader->exec('CREATE TABLE forbidden(id INTEGER)'));
            A::reject(static fn () => $reader->exec('DELETE FROM users'));
            Assert::same(A::snapshot($writer), $before); $reader = null; $writer = null;
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { unset($reader, $writer); $directory->cleanup($directory->close(...)); }
    }

    #[Test]
    public function tokenConstraintsRejectNonSingletonNoncanonicalDigestsAndInvalidCalendarDates(): void
    {
        $db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); ReadApiTokenSchema::migrate($db);
        foreach ([[2,str_repeat('a', times: 64),str_repeat('b', times: 64),A::NOW],[1,str_repeat('A', times: 64),str_repeat('b', times: 64),A::NOW],
            [1,str_repeat('a', times: 63),str_repeat('b', times: 64),A::NOW],[1,str_repeat('a', times: 64),str_repeat('b', times: 64),'2026-02-30T00:00:00Z'],
            [1,str_repeat('a', times: 64) . "\0suffix",str_repeat('b', times: 64),A::NOW],
            [1,str_repeat('a', times: 10) . "\0" . str_repeat('b', times: 53),str_repeat('b', times: 64),A::NOW],
            [1,str_repeat('a', times: 64),str_repeat('b', times: 64) . "\0suffix",A::NOW],
            [1,str_repeat('a', times: 64),str_repeat('b', times: 10) . "\0" . str_repeat('c', times: 53),A::NOW],
            [1,str_repeat('a', times: 64),str_repeat('b', times: 64),A::NOW . "\0suffix"]] as $values) {
            A::reject(static function () use ($db, $values): void { $statement = \Tablo\SqlStatement::checked($db->prepare('INSERT INTO read_api_token VALUES(?,?,?,?)')); try { $statement->execute($values); } finally { $statement->closeCursor(); } });
        }
        Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), []); $db = null;
    }
}
