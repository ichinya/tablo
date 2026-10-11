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
use Tablo\SiteChecker;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\UnitFixtures;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Testo\Assert;
use Testo\Test;

final class CheckHistoryTest
{
    private static function error(callable $action): ?string
    {
        try { $action(); } catch (\Throwable $error) { return $error->getMessage(); }
        return null;
    }

    #[Test]
    public function acceptedManualAndWorkerWritesPairHistoryAndOnlyRelevantCredit(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(UnitFixtures::site());
        $site = $sites->find($id);
        $state = ['online' => 1, 'checked_at' => '2026-01-01T05:00:00+05:00', 'response_time_ms' => 0,
            'latest_release' => null, 'latest_commit' => str_repeat('A', 40), 'last_error' => 'synthetic-private-marker'];
        Assert::true($sites->storeCheck($site, $state));
        Assert::true($sites->storeCheck($site, $state));
        $rows = $db->query('SELECT * FROM check_history ORDER BY id')->fetchAll();
        Assert::same(count($rows), 2);
        Assert::same($rows[0]['checked_at'], '2026-01-01T00:00:00Z');
        Assert::same($sites->find($id)['checked_at'], $state['checked_at'], 'last-state representation preserved');
        Assert::same($rows[0]['response_time_ms'], 0);
        Assert::same($rows[0]['config_revision'], $site['config_revision']);
        Assert::same($rows[0]['latest_commit'], str_repeat('a', 40));
        Assert::false(str_contains(json_encode($rows), 'synthetic-private-marker'));
        Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
        $worker = new WorkerStateRepository($db);
        $state['worker_service'] = ['latest_release' => true, 'latest_commit' => false, 'open_prs' => true];
        Assert::true($worker->settle($site, $state));
        Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 3);
        Assert::same($worker->turns($id, $site['config_revision']),
            ['latest_release' => 1, 'latest_commit' => 0, 'open_issues' => 0, 'open_prs' => 2]);
        $before = $db->query('SELECT * FROM sites')->fetch();
        foreach ([
            'BEFORE INSERT ON check_history BEGIN SELECT RAISE(ABORT, \'history-refusal\'); END',
            'BEFORE INSERT ON worker_progress BEGIN SELECT RAISE(ABORT, \'credit-refusal\'); END',
            'BEFORE UPDATE OF fairness_turn ON worker_runtime BEGIN SELECT RAISE(ABORT, \'turn-refusal\'); END',
        ] as $trigger) {
            $db->exec('DELETE FROM worker_progress');
            $db->exec('CREATE TRIGGER refusal ' . $trigger);
            Assert::true(self::error(fn () => $worker->settle($site, array_replace($state, ['online' => 0]))) !== null);
            Assert::same($db->query('SELECT * FROM sites')->fetch(), $before);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 3);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 2);
            $db->exec('DROP TRIGGER refusal');
        }
        $db->exec('CREATE TABLE deferred_control (parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)');
        $db->exec('CREATE TRIGGER refusal AFTER INSERT ON check_history BEGIN INSERT INTO deferred_control VALUES (-1); END');
        Assert::true(self::error(fn () => $worker->settle($site, $state)) !== null);
        Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 3);
        Assert::same((int) $db->query('SELECT COUNT(*) FROM deferred_control')->fetchColumn(), 0);
        Assert::same((int) $db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 2);
        $db->exec('DROP TRIGGER refusal');
        Assert::true($worker->settle($site, $state), 'same connection reused after COMMIT refusal');
    }

    #[Test]
    public function unattemptedGitFieldsStayUnknownWhileCompletedPartialSuccessRemainsKnown(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(UnitFixtures::site());
        $site = $sites->find($id);
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, '{"version":"v1"}')]),
            new FakeProvider()))->check($site, ['latest_commit']);
        Assert::true((new WorkerStateRepository($db))->settle($site, $state));
        $row = $db->query('SELECT * FROM check_history')->fetch();
        Assert::same($row['online'], 1);
        Assert::same($row['deployed_version'], 'v1');
        Assert::same($row['latest_release'], null);
        Assert::same($row['release_error_code'], 'check-error', 'unattempted is not confirmed no-release');
        Assert::same($row['latest_commit'], str_repeat('a', 40));
        Assert::same($row['commit_error_code'], null);
        Assert::same((new WorkerStateRepository($db))->turns($id, $site['config_revision']),
            ['latest_release' => 0, 'latest_commit' => 1, 'open_issues' => 0, 'open_prs' => 0]);
    }

    #[Test]
    public function validatesOnlyAcceptedSnapshotsAndPreservesCallerAndFirstFailure(): void
    {
        $db = new MigrationPdo(':memory:');
        Database::migrate($db);
        $sites = new SiteRepository($db);
        $id = $sites->save(UnitFixtures::site());
        $site = $sites->find($id);
        $good = ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z'];
        foreach ([[], ['checked_at' => '2026-02-30T00:00:00Z'], ['online' => 2], ['online' => '1'],
            ['health_error_code' => 'synthetic-secret'], ['health_http_status' => 600], ['response_time_ms' => -1],
            ['response_time_ms' => 2147483648], ['deployed_version' => str_repeat('x', 201)],
            ['deployed_commit' => 'invalid'], ['latest_release' => []], ['latest_commit' => 'bad'],
            ['version_status' => 'maybe'], ['version_error_code' => 'raw-marker'], ['commit_http_status' => 99]] as $bad) {
            $state = $bad === [] ? [] : array_replace($good, $bad);
            $message = self::error(fn () => $sites->storeCheck($site, $state));
            if (($bad['online'] ?? null) === 2 || ($bad['health_http_status'] ?? null) === 600) {
                Assert::true(str_contains($message ?? '', 'CHECK constraint failed'), 'existing sites CHECK is the original first error');
            } else { Assert::same($message, 'Invalid check history snapshot.'); }
            Assert::same($sites->find($id)['checked_at'], null);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
        }
        $sites->save(UnitFixtures::site(), $id);
        Assert::false($sites->storeCheck($site, ['checked_at' => 'invalid', 'health_error_code' => 'raw-marker']));
        Assert::false((new WorkerStateRepository($db))->settle($site, ['checked_at' => 'invalid', 'worker_service' => 'invalid']));
        $site = $sites->find($id);
        $db->beginTransaction();
        $db->exec('UPDATE installation_settings SET check_interval_minutes = 7');
        Assert::true(self::error(fn () => $sites->storeCheck($site, $good)) !== null);
        Assert::true($db->inTransaction());
        Assert::same((int) $db->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 7);
        $db->rollBack();
        $db->exec('CREATE TRIGGER refusal BEFORE INSERT ON check_history BEGIN SELECT RAISE(ROLLBACK, \'first-history-error\'); END');
        $db->failRollback = true;
        Assert::true(str_contains(self::error(fn () => $sites->storeCheck($site, $good)) ?? '', 'first-history-error'));
        $db->failRollback = false;
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('ROLLBACK'); // Actual engine state is decisive, including PHP82 cached PDO caveat.
        $db->exec('DROP TRIGGER refusal');
        Assert::true($sites->storeCheck($site, $good));
        $sites->save(array_replace(UnitFixtures::site(), ['enabled' => 0]), $id);
        Assert::false($sites->storeCheck($sites->find($id), $good));
    }

}
