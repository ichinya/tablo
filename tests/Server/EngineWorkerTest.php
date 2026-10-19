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
        ServerSchema::rows($db, 'INSERT INTO worker_progress(site_id,config_revision,latest_release,latest_commit,open_issues,open_prs) VALUES (?,0,3,5,7,9)', [$id]);
        $db->exec('UPDATE worker_runtime SET fairness_turn = 1000000000 WHERE id = 1');
        $state = new WorkerStateRepository($db, $sites);
        Assert::true($state->settle(FixtureValue::row($sites->find($id)), ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['latest_release' => true]]));
        Assert::same($state->turns($id, 0), ['latest_release' => 500_000_001, 'latest_commit' => 2, 'open_issues' => 3, 'open_prs' => 4]);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 500_000_001);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 1);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 1);
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
}
