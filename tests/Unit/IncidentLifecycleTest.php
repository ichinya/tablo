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
        Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), []);
        Assert::true(F::accept($sites, $id, 0, '2026-01-01T00:01:00Z', ['health_error_code' => 'http', 'health_http_status' => 503]));
        $opening = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($opening)['id'], 3);
        F::accept($sites, $id, 0, '2026-01-01T00:02:00Z', ['health_error_code' => 'json-condition', 'health_http_status' => 200]);
        Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), $opening);
        F::accept($sites, $id, null, '2026-01-01T00:03:00Z');
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT recovered_at FROM incidents'))->fetchColumn(), null);
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT uncertain FROM incidents'))->fetchColumn(), 1);
        F::accept($sites, $id, 1, '2026-01-01T00:11:00Z', ['last_error' => 'synthetic Git failure']);
        $closed = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($closed)['id'], 3);
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($closed)['recovery_history_id'], 6);
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($closed)['health_error_code'], 'http');
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($closed)['health_http_status'], 503);
        Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($closed)['end_reason'], 'recovered');
        $view = IncidentPresenter::row(\Tablo\Tests\Support\FixtureValue::at((new IncidentRepository($db))->page()['rows'], 0));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($view, 'interval_seconds'), 600);
        F::accept($sites, $id, 1, '2026-01-01T00:12:00Z');
        Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), $closed, 'recovery immutable');
        F::accept($sites, $id, 0, '2026-01-01T00:13:00Z');
        Assert::same(array_column(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT id FROM incidents ORDER BY id'))->fetchAll(), \Tablo\SqlSitesIdRow::checked(...)), 'id'), [3, 8]);
    }

    #[Test]
    public function pauseAbaEpochAndReadOverlayNeverFabricateRecoveryOrEditTime(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        F::accept($sites, $id, 0, '2026-01-01T00:00:00Z');
        $snapshot = $sites->find($id);
        $before = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
        $sites->save(array_replace(F::site(), ['enabled' => 0]), $id);
        Assert::false($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), ['checked_at' => 'invalid']));
        Assert::false(F::accept($sites, $id, 1, '2026-01-01T00:01:00Z'));
        $row = IncidentPresenter::row(\Tablo\Tests\Support\FixtureValue::at((new IncidentRepository($db))->page()['rows'], 0), \Tablo\Tests\Support\FixtureValue::integer(strtotime('2026-01-01T00:20:00Z')));
        Assert::true(in_array('На паузе', \Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($row, 'qualifiers')), strict: true));
        Assert::true(in_array('Конфигурация изменена; ожидается новая проверка', \Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($row, 'qualifiers')), strict: true));
        Assert::true(in_array('Нет новых наблюдений', \Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($row, 'qualifiers')), strict: true));
        Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetch(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), $before);
        $sites->save(F::site(), $id);
        Assert::false($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), ['checked_at' => 'invalid', 'health_error_code' => 'private']));
        F::accept($sites, $id, 0, '2025-01-01T00:00:00Z');
        $rows = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents ORDER BY id'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
        Assert::same(count($rows), 2);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($rows, 0)['end_reason'], 'config-changed');
        Assert::same(\Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::at($rows, 0))['recovered_at'], null);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($rows, 0)['interruption_history_id'], \Tablo\Tests\Support\FixtureValue::at($rows, 1)['id']);
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($rows, 1)['clock_invalid'], 0, 'new epoch resets watermark');
        F::accept($sites, $id, 1, '2025-01-01T00:00:01Z');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(IncidentPresenter::row(\Tablo\Tests\Support\FixtureValue::at((new IncidentRepository($db))->page()['rows'], 0)), 'interval_seconds'), 1);
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
        $equal = IncidentPresenter::row(\Tablo\Tests\Support\FixtureValue::at($read->page()['rows'], 0));
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($equal, 'end_reason'), 'recovered');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at($equal, 'recovery_history_id'), 2);
        Assert::same($equal['interval_seconds'], null);
        Assert::same($equal['interval_reason'], 'Точность времени — одна секунда');
        F::accept($sites, $id, 0, '2024-03-01T00:00:10Z');
        F::accept($sites, $id, 1, '2024-03-01T00:00:09Z');
        $older = \Tablo\Tests\Support\FixtureValue::at($read->page()['rows'], 0);
        Assert::same($older['end_reason'], null);
        Assert::same($older['uncertain'], 1);
        Assert::same($older['clock_invalid'], 1);
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT last_history_id FROM incident_checkpoints'))->fetchColumn(), 4);
        F::accept($sites, $id, 1, '2024-03-01T00:01:00Z');
        Assert::same(\Tablo\Tests\Support\FixtureValue::at(IncidentPresenter::row(\Tablo\Tests\Support\FixtureValue::at($read->page()['rows'], 0)), 'interval_seconds'), null);
        Assert::same(\Tablo\Tests\Support\FixtureValue::object(F::error(static fn () => F::accept($sites, $id, 0, '2025-02-29T00:00:00Z')))->getMessage(), 'Invalid check history snapshot.');
    }

    #[Test]
    public function retentionAndConsumedIdNoopPreserveAnchorsWhileSiteDeletionCascades(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        $other = $sites->save(F::site());
        F::accept($sites, $id, 0, '2020-01-01T00:00:00Z');
        $row = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT * FROM check_history'))->fetch(), \Tablo\SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow::checked(...)));
        F::accept($sites, $other, 0, '2020-01-01T00:00:00Z');
        $before = F::rows($db);
        $db->exec('BEGIN IMMEDIATE');
        (new \Tablo\IncidentProjector($db))->accepted(\Tablo\Tests\Support\FixtureValue::fetched($row)['id'], \Tablo\Tests\Support\FixtureValue::fetched($row));
        $db->exec('COMMIT');
        Assert::same(F::rows($db), $before);
        Assert::same((new CheckHistoryRepository($db))->pruneBatch('2021-01-01T00:00:00Z'), 2);
        Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)), \Tablo\Tests\Support\FixtureValue::at($before, 'incidents'));
        Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incident_checkpoints'))->fetchAll(), \Tablo\SqlIncidentCheckpointsRow::checked(...)), \Tablo\Tests\Support\FixtureValue::at($before, 'incident_checkpoints'));
        F::accept($sites, $id, 0, '2020-01-01T00:00:01Z');
        F::accept($sites, $id, 1, '2020-01-01T00:00:02Z');
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT recovery_history_id FROM incidents WHERE site_id=1'))->fetchColumn(), 4);
        $snapshot = $sites->find($id);
        $sites->delete($id);
        Assert::false($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), ['checked_at' => 'invalid']));
        Assert::same(array_column(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT site_id FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsSiteIdRow::checked(...)), 'site_id'), [$other]);
        Assert::same(array_column(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT site_id FROM incident_checkpoints'))->fetchAll(), \Tablo\SqlIncidentsSiteIdRow::checked(...)), 'site_id'), [$other]);
        Assert::true($sites->save(F::site()) > $other, 'no site identity reuse');
        Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(), []);
    }
}
