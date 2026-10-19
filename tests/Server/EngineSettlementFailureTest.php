<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\ServerSchema;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

/** Native statement refusals and application exceptions; server-loss/COMMIT uncertainty has its own required lane. */
final class EngineSettlementFailureTest
{
    #[Test]
    public function nativeCurrentResultConstraintRefusesBeforeHistoryOrProjectionWrites(): void
    { $fixture = new EngineFixture('settlement-current-failure'); try { engine_settlement_failure_constraint($fixture, table: 'sites', expression: 'checked_at IS NULL'); } finally { $fixture->close(); } }

    #[Test]
    public function nativeIncidentConstraintRollsBackCurrentResultAndHistory(): void
    { $fixture = new EngineFixture('settlement-incident-failure'); try { engine_settlement_failure_constraint($fixture, table: 'incidents', expression: 'id = 0'); } finally { $fixture->close(); } }

    #[Test]
    public function nativeIncidentCheckpointConstraintRollsBackTheOpeningIncident(): void
    { $fixture = new EngineFixture('settlement-incident-checkpoint-failure'); try { engine_settlement_failure_constraint($fixture, table: 'incident_checkpoints', expression: 'last_history_id = 0'); } finally { $fixture->close(); } }

    #[Test]
    public function nativeNotificationCheckpointConstraintRollsBackEarlierProjections(): void
    { $fixture = new EngineFixture('settlement-notification-checkpoint-failure'); try { engine_settlement_failure_constraint($fixture, table: 'notification_checkpoints', expression: 'last_history_id = 0'); } finally { $fixture->close(); } }

    #[Test]
    public function nativeWatermarkConstraintRollsBackHistoryProjectionsAndWorkerCredit(): void
    { $fixture = new EngineFixture('settlement-watermark-failure'); try { engine_settlement_failure_constraint($fixture, table: 'tablo_database_state', expression: 'committed_history_high_watermark = 0'); } finally { $fixture->close(); } }

    #[Test]
    public function nativeHistoryConstraintRollsBackTheEarlierSiteWrite(): void
    {
        $fixture = new EngineFixture('settlement-history-failure');
        engine_settlement_failure_constraint($fixture, table: 'check_history', expression: 'online IS NULL');
        $fixture->close();
    }

    #[Test]
    public function nativeCreditConstraintRollsBackHistoryIncidentsAndFairness(): void
    {
        $fixture = new EngineFixture('settlement-credit-failure');
        engine_settlement_failure_constraint($fixture, table: 'worker_progress', expression: 'open_issues = 0');
        $fixture->close();
    }

    #[Test]
    public function projectionExceptionPreservesItsIdentityAndRollsBackEarlierWrites(): void
    {
        $fixture = new EngineFixture('settlement-projection-failure');
        $db = $fixture->database(); $failure = new \RuntimeException('synthetic projection failure');
        $sites = new SiteRepository($db, $fixture->vault, static function () use ($failure): never { throw $failure; });
        $id = $sites->save(UnitFixtures::site()); $snapshot = FixtureValue::row($sites->find($id));
        $caught = null;
        try { (new WorkerStateRepository($db, $sites))->settle($snapshot, engine_settlement_failure_result()); }
        catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught, $failure);
        engine_settlement_failure_unchanged($fixture, $sites, $id);
        $fixture->close();
    }

    #[Test]
    public function nativeNotificationEnqueueConstraintKeepsThePreviousSampleAndEveryEarlierEffect(): void
    {
        $fixture = new EngineFixture('settlement-enqueue-failure');
        try {
            $db = $fixture->database(); $now = 1_000;
            (new \Tablo\NotificationSettings($db, $fixture->vault))->update(['revision' => '0', 'enabled' => '1',
                'endpoint' => 'https://synthetic.invalid/hook', 'unavailable' => '1', 'recovery' => '1', 'version_lag' => '1']);
            $sites = new SiteRepository($db, $fixture->vault, static function () use (&$now): int { return $now; });
            $id = $sites->save(UnitFixtures::site());
            Assert::true(\Tablo\Tests\Support\IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:00:00Z'));
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 0);
            $snapshot = FixtureValue::row($sites->find($id)); $observer = $fixture->independent();
            $before = engine_settlement_failure_projection_rows($observer); $maintenance = $fixture->maintenance();
            $maintenance->exec('ALTER TABLE notification_slots ADD CONSTRAINT synthetic_settlement_refusal CHECK (source_id = 0)');
            $now = 1_060; $result = engine_settlement_failure_result(); $result['checked_at'] = '2026-01-01T00:01:00Z'; $failure = null;
            try { (new WorkerStateRepository($db, $sites))->settle($snapshot, $result); } catch (DatabaseFailure $error) { $failure = $error; }
            Assert::same($failure?->category, 'constraint'); Assert::same($failure?->getPrevious(), null);
            Assert::false($db->inTransaction()); Assert::false(WriteTransaction::owns($db));
            Assert::same(engine_settlement_failure_projection_rows($observer), $before);
            $drop = getenv('TABLO_TEST_DB_DRIVER') === 'mysql' ? ' DROP CHECK ' : ' DROP CONSTRAINT ';
            $maintenance->exec('ALTER TABLE notification_slots' . $drop . 'synthetic_settlement_refusal');
            Assert::true((new WorkerStateRepository($db, $sites))->settle($snapshot, $result));
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 1);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 2);
            Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 1);
            Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), $fixture->scalar('SELECT MAX(id) FROM check_history'));
        } finally { $fixture->close(); }
    }

}

/** @return array<string, list<array>> */
function engine_settlement_failure_projection_rows(\PDO $db): array
{
    $rows = [];
    foreach (['sites', 'check_history', 'incidents', 'incident_checkpoints', 'notification_checkpoints', 'notification_slots',
        'worker_progress', 'worker_runtime', 'tablo_database_state'] as $table) { $rows[$table] = ServerSchema::rows($db, 'SELECT * FROM ' . $table . ' ORDER BY 1'); }
    return $rows;
}

function engine_settlement_failure_constraint(EngineFixture $fixture, string $table, string $expression): void
{
    $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
    $id = $sites->save(UnitFixtures::site()); $snapshot = FixtureValue::row($sites->find($id));
    $observer = $fixture->independent();
    $maintenance = $fixture->maintenance();
    // Fixed test-owned identifiers/expressions; only this nonce-owned catalog is changed.
    $maintenance->exec('ALTER TABLE ' . $table . ' ADD CONSTRAINT synthetic_settlement_refusal CHECK (' . $expression . ')');
    $caught = null;
    try { (new WorkerStateRepository($db, $sites))->settle($snapshot, engine_settlement_failure_result()); }
    catch (DatabaseFailure $error) { $caught = $error; }
    Assert::same($caught?->category, 'constraint'); Assert::same($caught?->getPrevious(), null);
    engine_settlement_failure_unchanged($fixture, $sites, $id, $observer);
    $drop = getenv('TABLO_TEST_DB_DRIVER') === 'mysql' ? ' DROP CHECK ' : ' DROP CONSTRAINT ';
    $maintenance->exec('ALTER TABLE ' . $table . $drop . 'synthetic_settlement_refusal');
    Assert::true((new WorkerStateRepository($db, $sites))->settle($snapshot, engine_settlement_failure_result()));
    Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 1);
    Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 1);
    Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), $fixture->scalar('SELECT MAX(id) FROM check_history'));
}

function engine_settlement_failure_result(): array
{
    return ['online' => 0, 'checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['open_issues' => true]];
}

function engine_settlement_failure_unchanged(EngineFixture $fixture, SiteRepository $sites, int $id, ?\PDO $observer = null): void
{
    Assert::false($fixture->database()->inTransaction()); Assert::false(WriteTransaction::owns($fixture->database()));
    Assert::same(FixtureValue::row($sites->find($id))['checked_at'], null);
    foreach (['check_history', 'incidents', 'incident_checkpoints', 'worker_progress', 'notification_slots', 'notification_checkpoints'] as $table) {
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM ' . $table), 0, $table);
    }
    Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 0);
    Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 0);
    // A new ordinary connection must reject the intentionally altered layout.
    // Observe rollback through the separate session admitted before failure injection.
    Assert::same(ServerSchema::rows($observer ?? $fixture->independent(), 'SELECT checked_at FROM sites WHERE id = ?', [$id])[0]['checked_at'] ?? null, null);
}
