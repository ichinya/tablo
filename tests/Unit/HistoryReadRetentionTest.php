<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use DateTimeImmutable;
use PDOException;
use Tablo\CheckHistoryRepository;
use Tablo\Database;
use Tablo\HistoryRetention;
use Tablo\HistoryTime;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HistoryReadRetentionTest
{
    private static function error(callable $action): ?string
    {
        try { $action(); } catch (\Throwable $error) { return $error->getMessage(); }
        return null;
    }

    #[Test]
    public function pagesByHalfOpenRangeTieKeyAndStableInsertionCeilingWithoutSideEffects(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(UnitFixtures::site());
        $other = $sites->save(array_replace(UnitFixtures::site(), ['name' => 'Other']));
        $site = $sites->find($id);
        foreach (['2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z', '2026-01-03T00:00:00Z'] as $time) {
            Assert::true($sites->storeCheck($site, ['checked_at' => $time]));
        }
        $history = new CheckHistoryRepository($db);
        $from = '2026-01-01T00:00:00Z'; $to = '2026-01-03T00:00:00Z';
        $page = $history->page($id, $from, $to, 1);
        Assert::same(array_column($page['rows'], 'id'), [3]);
        Assert::true($sites->storeCheck($site, ['checked_at' => $from])); // Late insert must not enter traversal.
        $db->exec('DELETE FROM check_history WHERE id = 2');
        $next = $history->page($id, $from, $to, 1, $page['next_cursor']);
        Assert::same(array_column($next['rows'], 'id'), [1]);
        Assert::same($next['next_cursor'], null, 'retention can reduce the remaining traversal');
        foreach ([[$other, $from, $to, 1, $page['next_cursor']], [$id, $from, '2026-01-04T00:00:00Z', 1, $page['next_cursor']],
            [$id, $from, $to, 0, null], [$id, $to, $from, 1, null], [$id, $from, $to, 1, str_repeat('x', 1025)],
            [$id, $from, $to, 1, 'e30'], [$id, $from, $to, 1, $page['next_cursor'] . '=']] as $args) {
            Assert::true(self::error(fn () => $history->page(...$args)) !== null);
        }
        Assert::same($history->page($other, $from, $to)['rows'], []);
        $db->exec('PRAGMA query_only = ON');
        Assert::same(count($history->page($id, $from, $to)['rows']), 3, 'page has no write/cleanup');
        $db->exec('PRAGMA query_only = OFF');
        $plan = $db->query("EXPLAIN QUERY PLAN SELECT id FROM check_history WHERE site_id = 1 AND checked_at >= '$from'
            AND checked_at < '$to' AND (checked_at,id) < ('$to',100) ORDER BY checked_at DESC,id DESC LIMIT 51")->fetchAll();
        Assert::true(str_contains(json_encode($plan), 'check_history_site_time'));
        Assert::false(str_contains(json_encode($plan), 'TEMP B-TREE'));
        Assert::true(str_contains(json_encode($db->query('EXPLAIN QUERY PLAN DELETE FROM sites WHERE id=1')->fetchAll()), 'check_history_site_time'));
        $sites->delete($id);
        Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
        Assert::same(self::error(fn () => $history->page($id, $from, $to)), 'History site not found.');
    }

    #[Test]
    public function boundedPruneRetainsEqualityCurrentStateAndPriorBatchesAfterFailure(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(UnitFixtures::site());
        $other = $sites->save(array_replace(UnitFixtures::site(), ['name' => 'Other']));
        $site = $sites->find($id);
        $cutoff = '2026-01-01T00:00:00Z';
        foreach (['2025-12-31T23:59:59Z', $cutoff, '2026-01-02T00:00:00Z'] as $time) { $sites->storeCheck($site, ['checked_at' => $time]); }
        $sites->storeCheck($sites->find($other), ['checked_at' => '2025-12-01T00:00:00Z']);
        $history = new CheckHistoryRepository($db);
        $before = $db->query('SELECT * FROM sites ORDER BY id')->fetchAll();
        Assert::same($history->prune(30, $id, new DateTimeImmutable('2026-01-31T05:00:00+05:00')), ['deleted' => 1, 'capped' => false]);
        Assert::same(array_column($db->query('SELECT * FROM check_history WHERE site_id=1 ORDER BY id')->fetchAll(), 'checked_at'), [$cutoff, '2026-01-02T00:00:00Z']);
        Assert::same($history->pruneBatch($cutoff, $id), 0);
        Assert::same($db->query('SELECT * FROM sites ORDER BY id')->fetchAll(), $before);
        $db->exec("INSERT INTO check_history(site_id,config_revision,checked_at,version_status)
            SELECT 1,0,'2025-01-01T00:00:00Z','skipped' FROM
            (WITH RECURSIVE n(i) AS (VALUES(1) UNION ALL SELECT i+1 FROM n WHERE i<5501) SELECT i FROM n)");
        Assert::same($history->prune(30, $id, new DateTimeImmutable('2026-01-31T00:00:00Z')), ['deleted' => 5000, 'capped' => true]);
        $db->exec('CREATE TRIGGER refusal BEFORE DELETE ON check_history BEGIN SELECT RAISE(ABORT, \'prune-refusal\'); END');
        Assert::true(str_contains(self::error(fn () => $history->pruneBatch($cutoff)) ?? '', 'prune-refusal'));
        Assert::same((int) $db->query("SELECT COUNT(*) FROM check_history WHERE site_id=1 AND checked_at<'$cutoff'")->fetchColumn(), 501);
        $db->exec('DROP TRIGGER refusal');
        Assert::same($history->prune(30, $id, new DateTimeImmutable('2026-01-31T00:00:00Z')), ['deleted' => 501, 'capped' => false]);
        Assert::same($db->query('SELECT * FROM sites ORDER BY id')->fetchAll(), $before);
        Assert::same($history->pruneBatch($cutoff), 1, 'other site remained until global prune');
        $db->exec("INSERT INTO check_history(site_id,config_revision,checked_at,version_status)
            SELECT 1,0,'2025-01-01T00:00:00Z','skipped' FROM
            (WITH RECURSIVE n(i) AS (VALUES(1) UNION ALL SELECT i+1 FROM n WHERE i<600) SELECT i FROM n)");
        $first = (int) $db->query('SELECT MIN(id) FROM check_history WHERE checked_at < ' . $db->quote($cutoff))->fetchColumn();
        $db->exec('CREATE TRIGGER later_refusal BEFORE DELETE ON check_history WHEN OLD.id >= ' . ($first + 500)
            . " BEGIN SELECT RAISE(ABORT, 'later-batch-refusal'); END");
        Assert::true(str_contains(self::error(fn () => $history->prune(30, $id, new DateTimeImmutable('2026-01-31T00:00:00Z'))) ?? '', 'later-batch-refusal'));
        Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history WHERE checked_at < ' . $db->quote($cutoff))->fetchColumn(), 100,
            'first 500-row batch remains committed after next batch fails in the same invocation');
        $db->exec('DROP TRIGGER later_refusal');
        Assert::same($history->prune(30, $id, new DateTimeImmutable('2026-01-31T00:00:00Z')), ['deleted' => 100, 'capped' => false]);
        $plan = $db->query("EXPLAIN QUERY PLAN SELECT id FROM check_history WHERE checked_at<'$cutoff' ORDER BY checked_at,id LIMIT 500")->fetchAll();
        Assert::true(str_contains(json_encode($plan), 'check_history_time'));
        Assert::false(str_contains(json_encode($plan), 'TEMP B-TREE'));
        $db->beginTransaction();
        $db->exec('UPDATE installation_settings SET check_interval_minutes=9');
        Assert::true(self::error(fn () => $history->pruneBatch($cutoff)) !== null);
        Assert::true($db->inTransaction());
        Assert::same((int) $db->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 9);
        $db->rollBack();
    }

    #[Test]
    public function rejectsNoncanonicalCalendarAndRetentionInputsWithoutCoercion(): void
    {
        Assert::same(HistoryTime::canonical('2024-02-29T05:00:00+05:00'), '2024-02-29T00:00:00Z');
        foreach (['2026-02-29T00:00:00Z', '2026-04-31T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T00:00:60Z',
            '0000-01-01T00:00:00Z', '2026-01-01 00:00:00', '2026-01-01T00:00:00+24:00', [], null] as $bad) {
            Assert::same(self::error(fn () => HistoryTime::canonical($bad)), 'Invalid history time.');
        }
        Assert::same(HistoryRetention::days(), 30);
        foreach (['1', '30', '3650'] as $good) { Assert::same(HistoryRetention::days($good), (int) $good); }
        foreach (['', '0', '00', '01', '-1', '+30', ' 30', '30 ', '3e1', '30.0', '3651', str_repeat('9', 100), true, 30, [], null] as $bad) {
            Assert::same(self::error(fn () => HistoryRetention::days($bad)), 'Invalid history retention.');
        }
    }
}
