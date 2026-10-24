<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineTransactionFailureTest
{
    #[Test]
    public function migrationPhaseAndPublishPreserveTheRawCallerTransactionBeforeAnyBarrier(): void
    {
        $fixture = new EngineFixture('migration-raw-caller'); $db = $fixture->maintenance();
        try {
            $observer = $fixture->independent(); $db->beginTransaction();
            $db->exec('UPDATE installation_settings SET check_interval_minutes = 17');
            engine_migration_caller_preserved($fixture, $db, $observer);
            Assert::false(WriteTransaction::owns($db));
            $db->rollBack(); Assert::false($db->inTransaction());
            Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $db), 10);
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            $fixture->close();
        }
    }

    #[Test]
    public function migrationPhaseAndPublishPreserveTheExactWriteTransactionOwner(): void
    {
        $fixture = new EngineFixture('migration-owned-caller'); $db = $fixture->database();
        $owner = WriteTransaction::begin($db);
        try {
            $observer = $fixture->independent();
            $db->exec('UPDATE installation_settings SET check_interval_minutes = 17');
            engine_migration_caller_preserved($fixture, $db, $observer);
            Assert::true(WriteTransaction::owns($db)); Assert::false(WriteTransaction::owns($observer));
            $owner->rollback(); Assert::false($db->inTransaction()); Assert::false(WriteTransaction::owns($db));
            Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $db), 10);
        } finally { $owner->rollbackPreserving(); $fixture->close(); }
    }

    #[Test]
    public function callerTransactionSurvivesRefusedWriterBeginWithItsWritesUncommitted(): void
    {
        $fixture = new EngineFixture('transaction-caller');
        $db = $fixture->database(); $observer = $fixture->independent();
        $db->beginTransaction(); $db->exec('UPDATE installation_settings SET check_interval_minutes = 17');
        $caught = null;
        try { WriteTransaction::begin($db); } catch (\LogicException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Caller owns database transaction.');
        Assert::true($db->inTransaction()); Assert::false(WriteTransaction::owns($db));
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $db), 17);
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $observer), 10);
        $db->rollBack();
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings'), 10);
        $fixture->close();
    }

    #[Test]
    public function nestedBorrowKeepsExactOwnerAndCannotCommitTheOuterTransaction(): void
    {
        $fixture = new EngineFixture('transaction-nested');
        $db = $fixture->database(); $observer = $fixture->independent();
        $owner = WriteTransaction::begin($db);
        try {
            Assert::true(WriteTransaction::owns($db)); Assert::false(WriteTransaction::owns($observer));
            WriteTransaction::run($db, static fn (): int|false => $db->exec('UPDATE installation_settings SET check_interval_minutes = 19'));
            Assert::true($db->inTransaction()); Assert::true(WriteTransaction::owns($db));
            Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $observer), 10);
            $owner->rollback();
        } finally { $owner->rollbackPreserving(); }
        Assert::false($db->inTransaction()); Assert::false(WriteTransaction::owns($db));
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings'), 10);
        $fixture->close();
    }

    #[Test]
    public function foreignConnectionRepositoryIsRefusedBeforeSettlementOrWriterGuard(): void
    {
        $fixture = new EngineFixture('transaction-connection');
        $db = $fixture->database(); $other = $fixture->independent();
        $caught = null;
        try { new WorkerStateRepository($db, new SiteRepository($other, $fixture->vault)); }
        catch (\LogicException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Settlement repository connection mismatch.');
        Assert::false(WriteTransaction::owns($db)); Assert::false(WriteTransaction::owns($other));
        Assert::false($db->inTransaction()); Assert::false($other->inTransaction());
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 0);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 0);
        $fixture->close();
    }

    #[Test]
    public function missingGuardRowRefusesWriterAndMaintenanceBeforeAnyMutation(): void
    {
        $fixture = new EngineFixture('transaction-guard');
        $db = $fixture->database(); $maintenance = $fixture->maintenance();
        $maintenance->exec('DELETE FROM tablo_writer_guard WHERE id = 1');
        $caught = null;
        try { WriteTransaction::run($db, static fn (): int|false => $db->exec('UPDATE installation_settings SET check_interval_minutes = 23')); }
        catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        Assert::false($db->inTransaction()); Assert::false(WriteTransaction::owns($db));
        $failure = null;
        try { \Tablo\DatabaseTransferTarget::atomic($maintenance, static fn (): int|false => $maintenance->exec('UPDATE installation_settings SET check_interval_minutes = 29')); }
        catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
        Assert::same($failure?->category, 'schema'); Assert::same($failure?->getPrevious(), null);
        Assert::false($maintenance->inTransaction());
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings'), 10);
        $fixture->close();
    }
}

/** Actual pending writes and an independent observer detect an implicit commit or rollback. */
function engine_migration_caller_preserved(EngineFixture $fixture, \PDO $db, \PDO $observer): void
{
    $metadata = \Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
    $journal = \Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY step');
    $points = [];
    $state = new \Tablo\ServerMigrationState($db, static function (string $phase, int $step) use (&$points): void { $points[] = [$phase, $step]; });
    foreach (['phase', 'publish'] as $operation) {
        $caught = null;
        try {
            if ($operation === 'phase') { $state->phase('migrating'); }
            if ($operation === 'publish') { $state->publish(10, 10); }
        } catch (\LogicException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Caller owns database transaction.');
        Assert::true($db->inTransaction()); Assert::same($points, []);
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $db), 17);
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $observer), 10);
        Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $metadata);
        Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY step'), $journal);
    }
}
