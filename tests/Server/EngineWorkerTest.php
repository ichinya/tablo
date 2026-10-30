<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseConfiguration;
use Tablo\DatabaseConnectionFactory;
use Tablo\DatabaseFailure;
use Tablo\DatabaseSession;
use Tablo\GitHubCooldownRepository;
use Tablo\ServerLock;
use Tablo\ServerSchema;
use Tablo\SiteCheckWriter;
use Tablo\SiteRepository;
use Tablo\WorkerLock;
use Tablo\WorkerStateRepository;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\IncidentFixtures;
use Testo\Assert;
use Testo\Test;

final class EngineWorkerTest
{
    #[Test]
    public function lifetimeWorkerLockCollidesAcrossSessionsAndSurvivesDmlCommit(): void
    {
        $fixture = new EngineFixture('worker-lifetime');
        $db = $fixture->database(); $other = $fixture->independent();
        $first = new WorkerLock(); $second = new WorkerLock();
        try {
            engine_worker_self_update($db, $other);
            Assert::true($first->acquire($db)); Assert::false($second->acquire($other));
            WriteTransaction::run($db, static fn (): int|false => $db->exec('UPDATE installation_settings SET check_interval_minutes = 17'));
            Assert::false($db->inTransaction()); Assert::false($second->acquire($other));
            $first->close(); Assert::true($second->acquire($other));
            Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $other), 17);
        } finally { $first->close(); $second->close(); $fixture->close(); }
    }

    #[Test]
    public function lostAdvisoryOwnershipIsObservedAndCannotBeReleasedAsAnotherOwner(): void
    {
        $fixture = new EngineFixture('worker-lock-loss');
        $db = $fixture->database(); $lock = new ServerLock(); $other = new ServerLock();
        Assert::true($lock->acquire($db, 'worker'));
        Assert::same($fixture->scalar('SELECT RELEASE_ALL_LOCKS()'), 1);
        $caught = null;
        try { $lock->assertOwned(); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'busy');
        $release = null;
        try { $lock->close(); } catch (DatabaseFailure $error) { $release = $error; }
        Assert::same($release?->category, 'busy');
        try { Assert::true($other->acquire($fixture->independent(), 'worker')); }
        finally { $other->close(); $fixture->close(); }
    }

    #[Test]
    public function keyIndependentControlUsesGenerationCasAndRepeatedStopKeepsMatchedRows(): void
    {
        $fixture = new EngineFixture('worker-control');
        $state = new WorkerStateRepository($fixture->database());
        $first = $state->begin();
        $control = DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment('control', $fixture->environment()));
        Assert::same(DatabaseSession::get($control)?->vault, null);
        Assert::same(DatabaseSession::get($control)?->keyAuthenticated, false);
        Assert::same(DatabaseSession::get($control)?->metadataObservation, null);
        $stop = new WorkerStateRepository($control);
        Assert::true($stop->requestStop($first)); Assert::true($stop->requestStop($first));
        $next = $state->begin(); Assert::false($first === $next);
        Assert::false($stop->requestStop($first)); $state->finish($first);
        Assert::same($state->generation(), $next); Assert::false($state->stopping($next));
        Assert::true($stop->requestStop($next)); Assert::true($state->stopping($next));
        $state->finish($next); Assert::same($state->generation(), null);
        Assert::false($stop->requestStop($next));
        Assert::same($fixture->scalar('SELECT stop_requested FROM worker_runtime'), 0);
        $fixture->close();
    }

    #[Test]
    public function oddServiceCreditRolloverUsesIntegerDivisionOnTheExactSettlementConnection(): void
    {
        $fixture = new EngineFixture('worker-credit');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $id = $sites->save(IncidentFixtures::site());
        $state = new WorkerStateRepository($db, $sites);
        $writes = new SiteCheckWriter($db);
        $credit = static function (int $revision) use ($db, $writes, $id): void {
            WriteTransaction::run($db, static function () use ($writes, $id, $revision): void { $writes->creditCheck($id, $revision, []); });
        };
        self::assertNewWorkerCredit($fixture, $state, $id, $credit);
        self::assertSameRevisionWorkerCredit($fixture, $state, $id, $credit);
        $db->exec('UPDATE worker_runtime SET fairness_turn = 1000000000 WHERE id = 1');
        Assert::true($state->settle(FixtureValue::row($sites->find($id)), ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['latest_release' => true]]));
        Assert::same($state->turns($id, 0), ['latest_release' => 500_000_001, 'latest_commit' => 2, 'open_issues' => 3, 'open_prs' => 4]);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 500_000_001);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 1);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 1);

        self::assertChangedRevisionWorkerCredit($fixture, $sites, $state, $id, $credit);
        self::assertStaleWorkerCredit($fixture, $state, $id, $credit);
        self::assertDisabledWorkerCredit($fixture, $sites, $state, $id, $credit);
        $fixture->close();
    }

    #[Test]
    public function cooldownMaximaPreserveNativePost2038PrimaryAndSharedSecondaryEligibility(): void
    {
        $fixture = new EngineFixture('worker-cooldown');
        $first = new GitHubCooldownRepository($fixture->database());
        $second = new GitHubCooldownRepository($fixture->independent());
        $first->defer('core', 2_147_483_648); $second->defer('core', 1);
        Assert::same($first->eligibleAt('core', includeSecondary: false), 2_147_483_648);
        $second->defer('secondary', 2_147_483_650); $first->defer('secondary', 2);
        Assert::same($first->eligibleAt('core'), 2_147_483_650);
        Assert::same($second->eligibleAt('core', includeSecondary: false), 2_147_483_648);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM github_cooldowns'), 2);
        $fixture->close();
    }

    /** @param \Closure(int): void $credit */
    private static function assertNewWorkerCredit(EngineFixture $fixture, WorkerStateRepository $state, int $id, \Closure $credit): void
    {
        $zeroCredit = ['latest_release' => 0, 'latest_commit' => 0, 'open_issues' => 0, 'open_prs' => 0];
        $credit(0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM worker_progress'), 1);
        Assert::same($fixture->scalar('SELECT config_revision FROM worker_progress'), 0);
        Assert::same($state->turns($id, 0), $zeroCredit);
    }

    /** @param \Closure(int): void $credit */
    private static function assertSameRevisionWorkerCredit(EngineFixture $fixture, WorkerStateRepository $state, int $id, \Closure $credit): void
    {
        $db = $fixture->database();
        ServerSchema::rows($db, 'UPDATE worker_progress SET latest_release = 3, latest_commit = 5, open_issues = 7, open_prs = 9 WHERE site_id = ?', [$id]);
        ServerSchema::rows($db, 'UPDATE sites SET latest_release = ?, latest_commit = ?, open_issues = 41, open_prs = 43 WHERE id = ?', ['source-release', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $id]);
        $credit(0);
        Assert::same($state->turns($id, 0), ['latest_release' => 3, 'latest_commit' => 5, 'open_issues' => 7, 'open_prs' => 9]);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 0);
    }

    /** @param \Closure(int): void $credit */
    private static function assertChangedRevisionWorkerCredit(EngineFixture $fixture, SiteRepository $sites, WorkerStateRepository $state, int $id, \Closure $credit): void
    {
        $zeroCredit = ['latest_release' => 0, 'latest_commit' => 0, 'open_issues' => 0, 'open_prs' => 0];
        $sites->save([...IncidentFixtures::site(), 'name' => 'Changed worker configuration'], $id);
        Assert::same($fixture->scalar('SELECT config_revision FROM sites'), 1);
        ServerSchema::rows($fixture->database(), 'UPDATE sites SET latest_release = ?, latest_commit = ?, open_issues = 47, open_prs = 53 WHERE id = ?', ['changed-source-release', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $id]);
        $credit(1);
        Assert::same($fixture->scalar('SELECT config_revision FROM worker_progress'), 1);
        Assert::same($state->turns($id, 1), $zeroCredit);
    }

    /** @param \Closure(int): void $credit */
    private static function assertStaleWorkerCredit(EngineFixture $fixture, WorkerStateRepository $state, int $id, \Closure $credit): void
    {
        ServerSchema::rows($fixture->database(), 'UPDATE worker_progress SET latest_release = 11, latest_commit = 13, open_issues = 17, open_prs = 19 WHERE site_id = ?', [$id]);
        $credit(0); // A stale configuration cannot overwrite current credit.
        Assert::same($fixture->scalar('SELECT config_revision FROM worker_progress'), 1);
        Assert::same($state->turns($id, 1), ['latest_release' => 11, 'latest_commit' => 13, 'open_issues' => 17, 'open_prs' => 19]);
    }

    /** @param \Closure(int): void $credit */
    private static function assertDisabledWorkerCredit(EngineFixture $fixture, SiteRepository $sites, WorkerStateRepository $state, int $id, \Closure $credit): void
    {
        $sites->save([...IncidentFixtures::site(), 'enabled' => 0], $id);
        Assert::same($fixture->scalar('SELECT config_revision FROM sites'), 2);
        $credit(2); // Disabled sites supply no INSERT SELECT row.
        Assert::same($fixture->scalar('SELECT config_revision FROM worker_progress'), 1);
        Assert::same($state->turns($id, 1), ['latest_release' => 11, 'latest_commit' => 13, 'open_issues' => 17, 'open_prs' => 19]);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM worker_progress'), 1);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 500_000_001);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 1);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 1);
    }
}

function engine_worker_self_update(\PDO $db, \PDO $other): void
{
    $first = new \Tablo\SelfUpdateRepository($db); $second = new \Tablo\SelfUpdateRepository($other);
    $build = new \Tablo\BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
    $claim = $first->claim($build, 1000) ?? throw new \RuntimeException('Native claim required.');
    Assert::same($second->claim($build, 1001), null);
    Assert::true($first->complete($claim, $build, 1001, new \Tablo\SelfUpdateResult('behind', str_repeat('b', times: 40), 3)));
    Assert::same($second->get()['new_commits'], 3); Assert::same($second->get()['successful_at'], 1001);
    $claim = $first->claim($build, 22_601) ?? throw new \RuntimeException('Native retry claim required.');
    Assert::true($first->complete($claim, $build, 22_602, new \Tablo\SelfUpdateResult('unknown', error: 'unavailable')));
    Assert::same($second->get()['status'], 'behind'); Assert::same($second->get()['successful_at'], 1001);
    $claim = $first->claim($build, 22_902) ?? throw new \RuntimeException('Native reconfiguration claim required.');
    $second->configure(null, '60');
    Assert::false($first->complete($claim, $build, 22_903, new \Tablo\SelfUpdateResult('current', $build->sha)));
    Assert::same($first->get()['enabled'], 0);
}
