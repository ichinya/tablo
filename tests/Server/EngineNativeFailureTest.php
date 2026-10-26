<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use PDO;
use Tablo\DatabaseFailure;
use Tablo\DatabaseInteger;
use Tablo\ServerSchema;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineNativeFailureTest
{
    #[Test]
    public function realGuardLockTimeoutReleasesOnlyItsOwnerAndNeverRunsOrReplaysTheOperation(): void
    {
        $fixture = new EngineFixture('transaction-native-timeout');
        $db = $fixture->database(); $blocker = $fixture->independent();
        $db->exec('SET SESSION innodb_lock_wait_timeout = 1'); $blocker->beginTransaction();
        $called = 0; $failure = null;
        try {
            ServerSchema::rows($blocker, 'SELECT id FROM tablo_writer_guard WHERE id = 1 FOR UPDATE');
            try { WriteTransaction::run($db, static function () use (&$called): void { $called++; }); }
            catch (DatabaseFailure $error) { $failure = $error; }
            Assert::same($failure?->category, 'busy'); Assert::same($failure?->getPrevious(), null);
            Assert::same($called, 0); Assert::false(WriteTransaction::owns($db)); Assert::false($db->inTransaction());
            Assert::true($blocker->inTransaction());
            Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $blocker), 10);
        } finally { $blocker->rollBack(); }
        WriteTransaction::run($db, static function () use ($db, &$called): void {
            $called++; $db->exec('UPDATE installation_settings SET check_interval_minutes = 17');
        });
        Assert::same($called, 1); Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $blocker), 17);
        $fixture->close();
    }

    #[Test]
    public function nativeDisconnectRollbackFailurePreservesTheOriginalApplicationExceptionAndOwnershipRelease(): void
    {
        $fixture = new EngineFixture('transaction-native-rollback');
        $db = $fixture->database(); $observer = $fixture->independent();
        $primary = new \RuntimeException('synthetic primary application failure'); $caught = null;
        try {
            WriteTransaction::run($db, static function () use ($db, $primary): void {
                $db->exec('UPDATE installation_settings SET check_interval_minutes = 19');
                self::disconnectSelf($db);
                throw $primary;
            });
        } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught, $primary); Assert::false(WriteTransaction::owns($db));
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $observer), 10);
        Assert::true(self::disconnected($db)); $fixture->close();
    }

    #[Test]
    public function actualCommitOnDisconnectedOwnedPdoIsConservativelyUncertainWithoutOperationReplay(): void
    {
        $fixture = new EngineFixture('transaction-native-commit');
        $db = $fixture->database(); $observer = $fixture->independent(); $called = 0; $failure = null;
        try {
            WriteTransaction::run($db, static function () use ($db, &$called): void {
                $called++; $db->exec('UPDATE installation_settings SET check_interval_minutes = 23'); self::disconnectSelf($db);
            });
        } catch (DatabaseFailure $error) { $failure = $error; }
        Assert::same($failure?->category, 'uncertain'); Assert::same($failure?->getPrevious(), null);
        Assert::same($called, 1); Assert::false(WriteTransaction::owns($db));
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $observer), 10);
        Assert::true(self::disconnected($db)); $fixture->close();
    }

    /** No enumeration or administrative connection: the statement targets its own still-open PDO session only. */
    private static function disconnectSelf(PDO $db): void
    {
        $rows = ServerSchema::rows($db, 'SELECT CONNECTION_ID() AS id');
        $id = DatabaseInteger::positive($rows[0]['id'] ?? null);
        try { $db->exec('KILL CONNECTION ' . $id); } catch (\PDOException $error) {
            Assert::true(in_array(DatabaseInteger::checked($error->errorInfo[1] ?? null), [1317, 1927, 2006, 2013], strict: true));
        }
        Assert::true(self::disconnected($db));
    }

    private static function disconnected(PDO $db): bool
    {
        try { ServerSchema::rows($db, 'SELECT 1 AS alive'); } catch (\PDOException $error) {
            Assert::true(in_array(DatabaseInteger::checked($error->errorInfo[1] ?? null), [1317, 1927, 2006, 2013], strict: true));
            return true;
        }
        return false;
    }
}
