<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitTokenRepository;
use Tablo\HttpClient;
use Tablo\PeriodicWorker;
use Tablo\SettingsRepository;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class PeriodicWorkerTest
{
    #[Test]
    public function reloadsCommittedIntervalAfterLongSerialPassAndWaitsMonotonically(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-clock-');
        try {
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $sites->save(array_replace(UnitFixtures::site(), ['repository' => 'example/project']));
            $time = 1000.0;
            $starts = [];
            $sleeps = [];
            $worker = null;
            $http = new class($path, $time) extends HttpClient {
                public int $health = 0;
                public function __construct(private string $path, private float &$time) {}
                public function get(string $url, array $headers = []): array
                {
                    if (!str_starts_with($url, 'https://api.github.com')) {
                        ++$this->health;
                        $this->time += 90; // Long synchronous operation; still a single pass.
                        $other = Database::connect($this->path);
                        (new SettingsRepository($other))->updateInterval('2');
                        return UnitFixtures::response(200, '{"version":"v1"}');
                    }
                    return UnitFixtures::response(200, str_contains($url, '/search/') ? '{"total_count":0,"incomplete_results":false}'
                        : (str_contains($url, '/branches/') ? '{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}' : '{"tag_name":"v1"}'));
                }
            };
            $worker = new PeriodicWorker($db, $http,
                connection: function () use (&$starts, &$time, $sites, $http): GitHubConnection {
                    $starts[] = $time;
                    return new GitHubConnection($sites, $http);
                }, clock: static function () use (&$time): float { return $time; },
                sleep: static function (float $seconds) use (&$time, &$sleeps): void { $sleeps[] = $seconds; $time += $seconds; },
                output: static function (string $message) use (&$worker, &$starts): void {
                    if ($message === 'Worker pass complete.' && count($starts) === 2) { $worker->stop(); }
                });
            Assert::same($worker->run(), 0);
            Assert::same(count($starts), 2);
            Assert::true(abs($starts[1] - $starts[0] - 300) < 0.001, 'two 90s HTTP checks then committed 120s completion wait');
            Assert::true(max($sleeps) <= 0.1);
            Assert::same(count($sleeps) > 1000, true);
            Assert::same((new WorkerStateRepository($db))->generation(), null);
        } finally { unset($worker, $http, $sites, $db); gc_collect_cycles(); $directory->close(); }
    }

    #[Test]
    public function rejectsAbaEditsRotationDeletionAndKeepsRenameOnlySnapshots(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-revisions-');
        try {
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $tokens = new GitTokenRepository($db);
            $token = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
            $input = array_replace(UnitFixtures::site(), ['repository' => 'example/project', 'git_token_id' => $token]);
            $id = $sites->save($input);
            $state = ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z'];
            $snapshot = $sites->find($id);
            Assert::true($sites->storeCheck($snapshot, $state));
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => ''], $token);
            Assert::true($sites->storeCheck($snapshot, $state), 'rename keeps ciphertext and revision');
            foreach (['aba', 'edit', 'rotation', 'individual', 'reselect', 'delete'] as $action) {
                $snapshot = $sites->find($id);
                match ($action) {
                    'aba' => (function () use ($sites, $input, $id): void { $sites->save(array_replace($input, ['enabled' => 0]), $id); $sites->save($input, $id); })(),
                    'edit' => $sites->save($input, $id),
                    'rotation' => $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))], $token),
                    'individual' => $sites->save(array_replace($input, ['git_token_id' => '', 'github_token' => bin2hex(random_bytes(24))]), $id),
                    'reselect' => $sites->save($input, $id),
                    'delete' => $sites->delete($id),
                };
                Assert::false($sites->storeCheck($snapshot, $state), $action . ' rejects old result');
            }
        } finally { unset($snapshot, $tokens, $sites, $db); $directory->close(); }
    }

    #[Test]
    public function isolatesProviderCheckAndStoreFailuresWithFixedDiagnosticsAndServiceCredit(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-errors-');
        try {
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            for ($i = 1; $i <= 4; ++$i) {
                $id = $sites->save(array_replace(UnitFixtures::site(), ['name' => 'Site ' . $i, 'repository' => 'example/project' . $i,
                    'version_path' => '', 'url' => 'https://example.com/' . $i]));
            }
            $db->exec("UPDATE sites SET github_token = 'invalid-fixture-ciphertext' WHERE id = 1;
                CREATE TRIGGER fail_store BEFORE UPDATE OF checked_at ON sites WHEN NEW.id = 3
                BEGIN SELECT RAISE(ABORT, 'synthetic-private-db'); END");
            $http = new class extends HttpClient {
                public function get(string $url, array $headers = []): array
                {
                    if (str_contains($url, '/project2/')) { throw new \RuntimeException('synthetic-private-provider'); }
                    if (str_contains($url, 'example.com/2')) { throw new \RuntimeException('synthetic-private-check'); }
                    return UnitFixtures::response(200, str_contains($url, '/search/') ? '{"total_count":0,"incomplete_results":false}'
                        : (str_contains($url, '/branches/') ? '{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}' : '{"tag_name":"v1"}'));
                }
            };
            $logs = [];
            $worker = new PeriodicWorker($db, $http, output: static function (string $message) use (&$logs): void { $logs[] = $message; });
            $worker->runPass();
            Assert::true($sites->find(1)['checked_at'] !== null);
            Assert::same($sites->find(1)['online'], 1, 'unavailable credentials still allow health');
            Assert::same($sites->find(2)['online'], null);
            Assert::false(str_contains($sites->find(2)['last_error'], 'synthetic-private'));
            Assert::same($sites->find(3)['checked_at'], null);
            Assert::true($sites->find(4)['checked_at'] !== null);
            Assert::false(str_contains(implode(' ', $logs), 'synthetic-private'));
            Assert::same($logs, ['Worker site checked.', 'Worker site failed.', 'Worker site checked.', 'Worker credentials unavailable.', 'Worker site checked.']);
            Assert::same((new WorkerStateRepository($db))->turns(1, 0), array_fill_keys(WorkerStateRepository::FIELDS, 0));
            Assert::true(min((new WorkerStateRepository($db))->turns(3, 0)) > 0, 'admitted service persists despite store failure');
            Assert::true(min((new WorkerStateRepository($db))->turns(2, 0)) > 0, 'admitted upstream failures receive credit');
        } finally { unset($worker, $http, $sites, $db); $directory->close(); }
    }
}
