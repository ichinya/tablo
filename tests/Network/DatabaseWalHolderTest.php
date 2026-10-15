<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;

use Tablo\Database;


use Tablo\Tests\Support\MigrationProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabaseWalHolderTest
{
    public static function assertLocked(MigrationProcess $holder, string $path): void
    {
        $holder->assertRunning();
        $probe = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $probe->exec('PRAGMA busy_timeout = 0');
        $code = null;
        try { $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK'); }
        catch (\PDOException $error) { $code = \Tablo\Tests\Support\FixtureNative::sqliteCode($error); }
        Assert::same($code, 5, 'Holder no longer owns the real SQLite writer lock');
        $holder->assertRunning();
    }

    #[Test]
    public function holderParentEofAbortsInsteadOfReportingSuccess(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-holder-eof-');
        $holder = null;
        try {
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            unset($db);
            $holder = new MigrationProcess($directory, 'holder', 'wal-holder', $path);
            $holder->awaitSignal('holder.locked');
            self::assertLocked($holder, $path);
            $holder->proceed(null);
            $result = $holder->wait();
            Assert::true($result['code'] !== 0);
            Assert::true(str_contains($result['stderr'], 'WAL holder parent pipe closed'));
            Assert::false(is_file($directory->path . '/holder.released'));
            $db = Database::connect($path);
            $db->exec('BEGIN IMMEDIATE');
            $db->exec('ROLLBACK');
        } finally {
            $holder?->close();
            unset($db);
            $directory->close();
        }
    }

}
