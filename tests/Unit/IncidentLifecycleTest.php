<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\CheckHistoryRepository;
use Tablo\Database;
use Tablo\IncidentPresenter;
use Tablo\IncidentRepository;
use Tablo\SiteRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
use Testo\Assert;
use Testo\Test;

final class IncidentLifecycleTest
{
    #[Test]
    public function firstRepeatUnknownRecoveryAndNewOpeningUseActualHistoryIdentity(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        foreach ([1, null] as $online) { Assert::true(F::accept($sites, $id, $online, '2026-01-01T00:00:00Z')); }
        Assert::same($db->query('SELECT * FROM incidents')->fetchAll(), []);
        Assert::true(F::accept($sites, $id, 0, '2026-01-01T00:01:00Z', ['health_error_code' => 'http', 'health_http_status' => 503]));
        $opening = $db->query('SELECT * FROM incidents')->fetch();
        Assert::same($opening['id'], 3);
        F::accept($sites, $id, 0, '2026-01-01T00:02:00Z', ['health_error_code' => 'json-condition', 'health_http_status' => 200]);
        Assert::same($db->query('SELECT * FROM incidents')->fetch(), $opening);
        F::accept($sites, $id, null, '2026-01-01T00:03:00Z');
        Assert::same($db->query('SELECT recovered_at FROM incidents')->fetchColumn(), null);
        Assert::same((int) $db->query('SELECT uncertain FROM incidents')->fetchColumn(), 1);
        F::accept($sites, $id, 1, '2026-01-01T00:11:00Z', ['last_error' => 'synthetic Git failure']);
        $closed = $db->query('SELECT * FROM incidents')->fetch();
        Assert::same($closed['id'], 3);
        Assert::same($closed['recovery_history_id'], 6);
        Assert::same($closed['health_error_code'], 'http');
        Assert::same($closed['health_http_status'], 503);
        Assert::same($closed['end_reason'], 'recovered');
        $view = IncidentPresenter::row((new IncidentRepository($db))->page()['rows'][0]);
        Assert::same($view['interval_seconds'], 600);
        F::accept($sites, $id, 1, '2026-01-01T00:12:00Z');
        Assert::same($db->query('SELECT * FROM incidents')->fetch(), $closed, 'recovery immutable');
        F::accept($sites, $id, 0, '2026-01-01T00:13:00Z');
        Assert::same(array_column($db->query('SELECT id FROM incidents ORDER BY id')->fetchAll(), 'id'), [3, 8]);
    }

    #[Test]
    public function pauseAbaEpochAndReadOverlayNeverFabricateRecoveryOrEditTime(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        F::accept($sites, $id, 0, '2026-01-01T00:00:00Z');
        $snapshot = $sites->find($id);
        $before = $db->query('SELECT * FROM incidents')->fetch();
        $sites->save(array_replace(F::site(), ['enabled' => 0]), $id);
        Assert::false($sites->storeCheck($snapshot, ['checked_at' => 'invalid']));
        Assert::false(F::accept($sites, $id, 1, '2026-01-01T00:01:00Z'));
        $row = IncidentPresenter::row((new IncidentRepository($db))->page()['rows'][0], strtotime('2026-01-01T00:20:00Z'));
        Assert::true(in_array('На паузе', $row['qualifiers'], true));
        Assert::true(in_array('Конфигурация изменена; ожидается новая проверка', $row['qualifiers'], true));
        Assert::true(in_array('Нет новых наблюдений', $row['qualifiers'], true));
        Assert::same($db->query('SELECT * FROM incidents')->fetch(), $before);
        $sites->save(F::site(), $id);
        Assert::false($sites->storeCheck($snapshot, ['checked_at' => 'invalid', 'health_error_code' => 'private']));
        F::accept($sites, $id, 0, '2025-01-01T00:00:00Z');
        $rows = $db->query('SELECT * FROM incidents ORDER BY id')->fetchAll();
        Assert::same(count($rows), 2);
        Assert::same($rows[0]['end_reason'], 'config-changed');
        Assert::same($rows[0]['recovered_at'], null);
        Assert::same($rows[0]['interruption_history_id'], $rows[1]['id']);
        Assert::same($rows[1]['clock_invalid'], 0, 'new epoch resets watermark');
        F::accept($sites, $id, 1, '2025-01-01T00:00:01Z');
        Assert::same(IncidentPresenter::row((new IncidentRepository($db))->page()['rows'][0])['interval_seconds'], 1);
    }

    #[Test]
    public function equalSecondAndOlderOnlineOrderByAcceptedIdentityWithClockQualification(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        F::accept($sites, $id, 0, '2024-02-29T23:59:59Z');
        F::accept($sites, $id, 1, '2024-02-29T23:59:59Z');
        $read = new IncidentRepository($db);
        $equal = IncidentPresenter::row($read->page()['rows'][0]);
        Assert::same($equal['end_reason'], 'recovered');
        Assert::same($equal['recovery_history_id'], 2);
        Assert::same($equal['interval_seconds'], null);
        Assert::same($equal['interval_reason'], 'Точность времени — одна секунда');
        F::accept($sites, $id, 0, '2024-03-01T00:00:10Z');
        F::accept($sites, $id, 1, '2024-03-01T00:00:09Z');
        $older = $read->page()['rows'][0];
        Assert::same($older['end_reason'], null);
        Assert::same($older['uncertain'], 1);
        Assert::same($older['clock_invalid'], 1);
        Assert::same((int) $db->query('SELECT last_history_id FROM incident_checkpoints')->fetchColumn(), 4);
        F::accept($sites, $id, 1, '2024-03-01T00:01:00Z');
        Assert::same(IncidentPresenter::row($read->page()['rows'][0])['interval_seconds'], null);
        Assert::same(F::error(fn () => F::accept($sites, $id, 0, '2025-02-29T00:00:00Z'))->getMessage(), 'Invalid check history snapshot.');
    }

    #[Test]
    public function retentionAndConsumedIdNoopPreserveAnchorsWhileSiteDeletionCascades(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        $other = $sites->save(F::site());
        F::accept($sites, $id, 0, '2020-01-01T00:00:00Z');
        $row = $db->query('SELECT * FROM check_history')->fetch();
        F::accept($sites, $other, 0, '2020-01-01T00:00:00Z');
        $method = new \ReflectionMethod(SiteRepository::class, 'projectIncident');
        $before = F::rows($db);
        $db->exec('BEGIN IMMEDIATE');
        $method->invoke($sites, $row['id'], $row);
        $db->exec('COMMIT');
        Assert::same(F::rows($db), $before);
        Assert::same((new CheckHistoryRepository($db))->pruneBatch('2021-01-01T00:00:00Z'), 2);
        Assert::same($db->query('SELECT * FROM incidents')->fetchAll(), $before['incidents']);
        Assert::same($db->query('SELECT * FROM incident_checkpoints')->fetchAll(), $before['incident_checkpoints']);
        F::accept($sites, $id, 0, '2020-01-01T00:00:01Z');
        F::accept($sites, $id, 1, '2020-01-01T00:00:02Z');
        Assert::same($db->query('SELECT recovery_history_id FROM incidents WHERE site_id=1')->fetchColumn(), 4);
        $snapshot = $sites->find($id);
        $sites->delete($id);
        Assert::false($sites->storeCheck($snapshot, ['checked_at' => 'invalid']));
        Assert::same(array_column($db->query('SELECT site_id FROM incidents')->fetchAll(), 'site_id'), [$other]);
        Assert::same(array_column($db->query('SELECT site_id FROM incident_checkpoints')->fetchAll(), 'site_id'), [$other]);
        Assert::true($sites->save(F::site()) > $other, 'no site identity reuse');
        Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
    }
}
