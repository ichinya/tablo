<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\NotificationLock;
use Tablo\NotificationOutbox;
use Tablo\NotificationSettings;
use Tablo\ServerSchema;
use Tablo\SiteRepository;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\ReadApiAssertions;
use Testo\Assert;
use Testo\Test;

/** Native session interleavings; simultaneous process delivery/crash schedules remain separate acceptance cases. */
final class EngineNotificationRaceTest
{
    #[Test]
    public function acceptedConfirmationCreatesOneEventAndDuplicateSampleCannotReplaceIt(): void
    {
        $fixture = new EngineFixture('notification-duplicate'); self::pending($fixture);
        $db = $fixture->database();
        $before = ServerSchema::rows($db, 'SELECT event_id,source_id,payload,private_cipher,coalesced,attempts FROM notification_slots');
        Assert::same(count($before), 1); Assert::same($before[0]['source_id'] ?? null, 1);
        $sites = new SiteRepository($fixture->independent(), $fixture->vault, static fn (): int => 1_070);
        Assert::true(IncidentFixtures::accept($sites, 1, 0, '2026-01-01T00:01:00Z'));
        Assert::same(ServerSchema::rows($db, 'SELECT event_id,source_id,payload,private_cipher,coalesced,attempts FROM notification_slots'), $before);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 3);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 3);
        $fixture->close();
    }

    #[Test]
    public function independentClaimOwnersCollideAndOnlyOneCurrentAcknowledgementCommits(): void
    {
        $fixture = new EngineFixture('notification-claim'); self::pending($fixture);
        $db = $fixture->database(); $other = $fixture->independent();
        $firstLock = new NotificationLock(); $secondLock = new NotificationLock();
        $first = new NotificationOutbox($db); $second = new NotificationOutbox($other);
        try {
            Assert::true($firstLock->acquire($db)); Assert::false($secondLock->acquire($other));
            $claim = FixtureValue::fetched($first->claim(1_060));
            Assert::false($db->inTransaction());
            Assert::true($first->acknowledge($claim, ['code' => 'sent', 'http_status' => 204], 1_061));
            $firstLock->close(); Assert::true($secondLock->acquire($other));
            Assert::false($second->claim(1_062));
            Assert::false($second->acknowledge($claim, ['code' => 'sent', 'http_status' => 204], 1_062));
            Assert::same(ServerSchema::rows($other, 'SELECT status,attempts,acknowledged_at,claim,lease_until FROM notification_slots'),
                [['status' => 'sent', 'attempts' => 1, 'acknowledged_at' => 1_061, 'claim' => null, 'lease_until' => null]]);
        } finally { $firstLock->close(); $secondLock->close(); $fixture->close(); }
    }

    #[Test]
    public function channelReplacementCancelsAReleasedInflightClaimAndRejectsItsOldAck(): void
    {
        $fixture = new EngineFixture('notification-rotation'); self::pending($fixture);
        $db = $fixture->database(); $outbox = new NotificationOutbox($db); $lock = new NotificationLock();
        Assert::true($lock->acquire($db));
        try { $claim = FixtureValue::fetched($outbox->claim(1_060)); } finally { $lock->close(); }
        $other = $fixture->independent(); $settings = new NotificationSettings($other, $fixture->vault);
        $settings->update(['revision' => '1', 'enabled' => '1', 'endpoint' => 'https://replacement.example/hook',
            'unavailable' => '1', 'recovery' => '1', 'version_lag' => '1']);
        Assert::false($outbox->acknowledge($claim, ['code' => 'sent', 'http_status' => 204], 1_061));
        Assert::same(ServerSchema::rows($db, 'SELECT status,claim,lease_until,private_cipher,acknowledged_at FROM notification_slots'),
            [['status' => 'cancelled', 'claim' => null, 'lease_until' => null, 'private_cipher' => null, 'acknowledged_at' => null]]);
        Assert::same($fixture->scalar('SELECT revision FROM notification_settings'), 2);
        Assert::same($fixture->scalar('SELECT activation_id FROM notification_settings'), 2);
        $fixture->close();
    }

    #[Test]
    public function expiredOwnerConsumesAttemptAndKeepsAmbiguousResultWithoutInventingSuccess(): void
    {
        $fixture = new EngineFixture('notification-lease'); self::pending($fixture);
        $db = $fixture->database(); $outbox = new NotificationOutbox($db); $lock = new NotificationLock();
        Assert::true($lock->acquire($db));
        try { $old = FixtureValue::fetched($outbox->claim(1_060)); } finally { $lock->close(); }
        Assert::false($outbox->acknowledge($old, ['code' => 'sent', 'http_status' => 204], 1_090));
        $other = $fixture->independent(); $second = new NotificationOutbox($other);
        Assert::true($lock->acquire($other));
        try {
            Assert::false($second->claim(1_090));
            Assert::same(ServerSchema::rows($other, 'SELECT status,attempts,last_code,acknowledged_at,due_at FROM notification_slots'),
                [['status' => 'pending', 'attempts' => 1, 'last_code' => 'ambiguous', 'acknowledged_at' => null, 'due_at' => 1_150]]);
            $next = FixtureValue::fetched($second->claim(1_150));
            Assert::same($next['attempt'], 2);
            Assert::false($outbox->acknowledge($old, ['code' => 'sent', 'http_status' => 204], 1_151));
            Assert::true($second->acknowledge($next, ['code' => 'ambiguous'], 1_151));
            Assert::same(ServerSchema::rows($other, 'SELECT status,attempts,last_code,acknowledged_at FROM notification_slots'),
                [['status' => 'pending', 'attempts' => 2, 'last_code' => 'ambiguous', 'acknowledged_at' => null]]);
        } finally { $lock->close(); $fixture->close(); }
    }

    #[Test]
    public function thirtyTwoStaleMaintenanceRowsCannotHideTheFreshEligibleClaim(): void
    {
        $fixture = new EngineFixture('notification-maintenance');
        $db = $fixture->database(); self::configure($fixture);
        for ($id = 1; $id <= 33; $id++) {
            ReadApiAssertions::insert($db, $id);
            ServerSchema::rows($db, "INSERT INTO notification_slots(site_id,event,event_id,source_id,config_revision,channel_revision,payload,created_at,expires_at,due_at,status,attempts)
                VALUES (?,'unavailable',?,1,0,0,'{}',1000,4600,1000,'pending',0)", [$id, 'stale-' . $id]);
        }
        $now = 1_000;
        $sites = new SiteRepository($db, $fixture->vault, static function () use (&$now): int { return $now; });
        $id = $sites->save(IncidentFixtures::site()); Assert::same($id, 34);
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:00:00Z'));
        $now = 1_060;
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:01:00Z'));
        $lock = new NotificationLock(); Assert::true($lock->acquire($db));
        try {
            $claim = FixtureValue::fetched((new NotificationOutbox($db))->claim(1_060));
            Assert::same($claim['slot']['site_id'], 34);
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM notification_slots WHERE status = 'cancelled'"), 32);
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM notification_slots WHERE status = 'pending'"), 1);
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM notification_slots WHERE status = 'inflight'"), 1);
        } finally { $lock->close(); $fixture->close(); }
    }

    private static function configure(EngineFixture $fixture): void
    {
        (new NotificationSettings($fixture->database(), $fixture->vault))->update(['revision' => '0', 'enabled' => '1',
            'endpoint' => 'https://receiver.example/hook', 'unavailable' => '1', 'recovery' => '1', 'version_lag' => '1']);
    }

    private static function pending(EngineFixture $fixture): void
    {
        self::configure($fixture); $now = 1_000;
        $sites = new SiteRepository($fixture->database(), $fixture->vault, static function () use (&$now): int { return $now; });
        $id = $sites->save(IncidentFixtures::site());
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:00:00Z'));
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 0);
        $now = 1_060; Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:01:00Z'));
    }
}
