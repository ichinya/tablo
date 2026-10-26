<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\CheckHistoryRepository;
use Tablo\NotificationSettings;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\SiteRepository;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Tablo\Tests\Support\IncidentFixtures;
use Testo\Assert;
use Testo\Test;

final class EngineWatermarkTest
{
    #[Test]
    public function rolledBackAllocationGapCannotBecomeProspectiveActivationOrAnEvent(): void
    {
        $fixture = new EngineFixture('watermark-gap');
        $db = $fixture->database(); $now = 1_000;
        $sites = new SiteRepository($db, $fixture->vault, static function () use (&$now): int { return $now; });
        $id = $sites->save(IncidentFixtures::site());
        Assert::true(IncidentFixtures::accept($sites, $id, 1, '2026-01-01T00:00:00Z'));
        $maintenance = $fixture->maintenance(); $maintenance->exec('ALTER TABLE check_history AUTO_INCREMENT = 9000');
        $owner = WriteTransaction::begin($db);
        try {
            ServerSchema::rows($db, "INSERT INTO check_history(site_id,config_revision,checked_at,version_status) VALUES (?,0,'2026-01-01T00:00:01Z','skipped')", [$id]);
            Assert::same($db->lastInsertId(), '9000');
        } finally { $owner->rollback(); }
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 1);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 1);
        $settings = new NotificationSettings($db, $fixture->vault);
        $settings->update(['revision' => '0', 'enabled' => '1', 'endpoint' => 'https://receiver.example/hook',
            'unavailable' => '1', 'recovery' => '1', 'version_lag' => '1']);
        Assert::same($fixture->scalar('SELECT activation_id FROM notification_settings'), 1);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 0);
        Assert::same((new CheckHistoryRepository($db))->pruneBatch('2026-01-02T00:00:00Z'), 1);
        $now = 1_060; Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:01:00Z'));
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 0);
        $now = 1_120; Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-01-01T00:02:00Z'));
        Assert::same(ServerSchema::rows($db, 'SELECT event,source_id FROM notification_slots'), [['event' => 'unavailable', 'source_id' => 9_001]]);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 9_002);
        $fixture->close();
    }

    #[Test]
    public function pruneAllSiteDeletionAndRecognizedMaintenanceRerunKeepCommittedWatermark(): void
    {
        $fixture = new EngineFixture('watermark-prune');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $maintenance = $fixture->maintenance(); $maintenance->exec('ALTER TABLE check_history AUTO_INCREMENT = 4000');
        $id = $sites->save(IncidentFixtures::site());
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2020-01-01T00:00:00Z'));
        Assert::same((new CheckHistoryRepository($db))->pruneBatch('2021-01-01T00:00:00Z'), 1);
        $sites->delete($id);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incidents'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incident_checkpoints'), 0);
        (new ServerMigration($maintenance, $fixture->vault))->initialize(EngineSchedule::receipt($fixture->case));
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 4_000);
        Assert::same((new \Tablo\NotificationSettingsStore($db))->activation(0), 4_000);
        $next = $sites->save(IncidentFixtures::site());
        Assert::true($next > $id);
        Assert::true(IncidentFixtures::accept($sites, $next, 1, '2020-01-01T00:00:01Z'));
        Assert::same($fixture->scalar('SELECT id FROM check_history'), 4_001);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 4_001);
        $fixture->close();
    }
}
