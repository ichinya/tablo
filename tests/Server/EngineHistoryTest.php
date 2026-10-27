<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use PDO;
use Tablo\AvailabilityRepository;
use Tablo\CheckHistoryRepository;
use Tablo\IncidentRepository;
use Tablo\ServerSchema;
use Tablo\SiteRepository;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\IncidentFixtures;
use Testo\Assert;
use Testo\Test;

final class EngineHistoryTest
{
    #[Test]
    public function stableHistoryCursorExcludesLateInsertionAndSurvivesRetention(): void
    {
        $fixture = new EngineFixture('history-page');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $id = $sites->save(IncidentFixtures::site()); $site = FixtureValue::row($sites->find($id));
        foreach (['0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z', '0001-01-02T00:00:00Z', '0001-01-03T00:00:00Z'] as $index => $time) {
            Assert::true($sites->storeCheck($site, ['checked_at' => $time,
                'online' => match ($index) { 0 => 1, 2 => 0, default => null },
                'response_time_ms' => match ($index) { 0 => 0, 2 => 40, default => null }]));
        }
        $history = new CheckHistoryRepository($db);
        $page = $history->page($id, '0001-01-01T00:00:00Z', '0001-01-03T00:00:00Z', 1);
        Assert::same(array_column($page['rows'], 'id'), [3]);
        Assert::true($sites->storeCheck($site, ['checked_at' => '0001-01-01T00:00:00Z']));
        $db->exec('DELETE FROM check_history WHERE id = 2');
        $next = $history->page($id, '0001-01-01T00:00:00Z', '0001-01-03T00:00:00Z', 1, $page['next_cursor']);
        Assert::same(array_column($next['rows'], 'id'), [1]); Assert::same($next['next_cursor'], null);
        $readonly = $fixture->independent();
        $readonly->exec('SET TRANSACTION READ ONLY'); $readonly->beginTransaction();
        try {
            Assert::same(count((new CheckHistoryRepository($readonly))->page($id, '0001-01-01T00:00:00Z', '0001-01-03T00:00:00Z')['rows']), 3);
            $availability = (new AvailabilityRepository($readonly))->read($id, '0001-01-01T00:00:00Z', '0001-01-03T00:00:00Z');
            Assert::same([$availability['overflow'], $availability['site']['id']], [false, $id]);
            Assert::same(array_column($availability['rows'], 'id'), [1, 5, 3]);
            Assert::same(array_column($availability['rows'], 'online'), [1, null, 0]);
            Assert::same(array_column($availability['rows'], 'response_time_ms'), [0, null, 40]);
            Assert::same(array_column($availability['rows'], 'checked_at'), ['0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z', '0001-01-02T00:00:00Z']);
            $firstAvailability = $availability['rows'][0] ?? throw new \RuntimeException('Expected populated availability sample.');
            Assert::same(array_keys($firstAvailability), ['id', 'config_revision', 'checked_at', 'online', 'response_time_ms']);
        }
        finally { $readonly->rollBack(); }
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 5);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 4);
        $fixture->close();
    }

    #[Test]
    public function pruneUsesStrictEqualityAndCommitsOnlyTenFiveHundredRowBatches(): void
    {
        $fixture = new EngineFixture('history-prune');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $id = $sites->save(IncidentFixtures::site());
        Assert::true(IncidentFixtures::accept($sites, $id, 1, '2026-01-01T00:00:00Z'));
        self::oldRows($db, $id, 5_501);
        $before = ServerSchema::rows($db, 'SELECT * FROM sites');
        $history = new CheckHistoryRepository($db);
        Assert::same($history->prune(30, $id, new \DateTimeImmutable('2026-01-31T00:00:00Z')), ['deleted' => 5_000, 'capped' => true]);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 502);
        Assert::same($history->prune(30, $id, new \DateTimeImmutable('2026-01-31T00:00:00Z')), ['deleted' => 501, 'capped' => false]);
        Assert::same(ServerSchema::rows($db, 'SELECT checked_at FROM check_history'), [['checked_at' => '2026-01-01T00:00:00Z']]);
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM sites'), $before);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 5_502);
        Assert::same($history->pruneBatch('2026-01-01T00:00:00Z'), 0);
        $fixture->close();
    }

    #[Test]
    public function oneOpenIncidentKeepsEqualSecondOlderClockAndRetainedAnchorFacts(): void
    {
        $fixture = new EngineFixture('history-incidents');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $id = $sites->save(IncidentFixtures::site());
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2024-02-29T23:59:59Z'));
        Assert::true(IncidentFixtures::accept($sites, $id, 1, '2024-02-29T23:59:59Z'));
        Assert::same(ServerSchema::rows($db, 'SELECT id,recovery_history_id,end_reason FROM incidents'), [['id' => 1, 'recovery_history_id' => 2, 'end_reason' => 'recovered']]);
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2024-03-01T00:00:10Z'));
        Assert::true(IncidentFixtures::accept($sites, $id, 1, '2024-03-01T00:00:09Z'));
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incidents WHERE end_reason IS NULL'), 1);
        Assert::same(ServerSchema::rows($db, 'SELECT id,clock_invalid,uncertain FROM incidents WHERE end_reason IS NULL'), [['id' => 3, 'clock_invalid' => 1, 'uncertain' => 1]]);
        $before = (new IncidentRepository($db))->page()['rows'];
        Assert::same((new CheckHistoryRepository($db))->pruneBatch('2025-01-01T00:00:00Z'), 4);
        Assert::same((new IncidentRepository($db))->page()['rows'], $before);
        Assert::same($fixture->scalar('SELECT last_history_id FROM incident_checkpoints'), 4);
        $sites->delete($id);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incidents'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incident_checkpoints'), 0);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 4);
        $fixture->close();
    }

    private static function oldRows(PDO $db, int $id, int $count): void
    {
        WriteTransaction::run($db, static function () use ($db, $id, $count): void {
            for ($remaining = $count; $remaining > 0; $remaining -= 500) {
                $size = min($remaining, 500); $values = [];
                for ($index = 0; $index < $size; $index++) { $values = [...$values, $id, 0, '2025-01-01T00:00:00Z', 'skipped']; }
                ServerSchema::rows($db, 'INSERT INTO check_history(site_id,config_revision,checked_at,version_status) VALUES '
                    . implode(',', array_fill(0, $size, value: '(?,?,?,?)')), $values);
            }
            $db->exec('UPDATE tablo_database_state SET committed_history_high_watermark = (SELECT MAX(id) FROM check_history) WHERE id = 1');
        });
    }
}
