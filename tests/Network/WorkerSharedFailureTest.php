<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\PeriodicWorker;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\WorkerLock;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Tablo\Tests\Support\WorkerHttp;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class WorkerSharedFailureTest
{
    private const FAILURE = "Worker failed. Check installation configuration and local storage.\n";

    #[Test]
    public function realCliReadonlyAfterStartupRefusesNextSiteAndReleasesLockEvenWhenFinishFails(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-readonly-r1-');
        $server = $child = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            self::sites($db, $directory, $server->base);
            $child = new WorkerProcess($directory, 'readonly', [PHP_BINARY, '-d',
                'auto_prepend_file=' . dirname(__DIR__) . '/fixtures/worker-readonly-prepend.php', 'bin/worker.php'],
                ['TABLO_DB' => $directory->path . '/db.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            $result = $child->wait();
            Assert::same($result['exit_code'], 1);
            Assert::same($result['stderr'], self::FAILURE);
            Assert::false(str_contains($result['stdout'], 'Worker pass complete.'));
            Assert::same(self::requests($directory), ['/up'], 'one actual health request; no next site/pass');
            Assert::same((int) $db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
            $lock = new WorkerLock();
            Assert::true($lock->acquire($db), 'OS successor acquisition after exit1 despite failed finish');
            $lock->close();
        } finally { $child?->close(); $server?->close(); unset($lock, $child, $server, $db); $directory->close(); }
    }

    #[Test]
    public function realCliRefusesMissingCorruptSharedKeyAndRemovalDuringActualHealthRequest(): void
    {
        foreach (['missing', 'corrupt', 'empty', 'live', 'live-replacement'] as $kind) {
            $directory = new TemporaryDirectory('tablo-worker-key-r1-');
            $child = $server = null;
            try {
                $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                    environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
                $db = Database::connect($directory->path . '/db.sqlite');
                $live = str_starts_with($kind, 'live');
                self::sites($db, $directory, $server->base, $live ? '/barrier' : '/up');
                $key = $directory->path . '/github-token.key';
                $hash = hash_file('sha256', $key);
                if (!$live) {
                    rename($key, $key . '.held');
                    if ($kind !== 'missing') { file_put_contents($key, $kind === 'corrupt' ? 'invalid' : ''); }
                }
                $child = new WorkerProcess($directory, $kind, [PHP_BINARY, 'bin/worker.php'],
                    ['TABLO_DB' => $directory->path . '/db.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
                if ($live) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($directory->path . '/entered') && $child->running() && microtime(true) < $deadline) {
                        clearstatcache(); usleep(20000);
                    }
                    Assert::true(is_file($directory->path . '/entered'), 'real health request reached barrier');
                    // A separate real PDO writer remains possible while network work is blocked.
                    $other = Database::connect($directory->path . '/db.sqlite');
                    $other->exec('UPDATE installation_settings SET check_interval_minutes = 2');
                    unset($other);
                    rename($key, $key . '.held');
                    if ($kind === 'live-replacement') { file_put_contents($key, random_bytes(32)); }
                    file_put_contents($directory->path . '/release', 'ready');
                }
                $result = $child->wait();
                Assert::same($result['exit_code'], 1, $kind);
                Assert::same($result['stderr'], self::FAILURE, $kind);
                Assert::false(str_contains($result['stdout'], 'Worker pass complete.'));
                Assert::same(self::requests($directory), $live ? ['/barrier'] : [], $kind);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL')->fetchColumn(), 0);
                Assert::same(hash_file('sha256', $key . '.held'), $hash);
                if (in_array($kind, ['missing', 'live'], true)) { Assert::false(file_exists($key), 'no replacement/fallback key'); }
                elseif ($kind === 'live-replacement') {
                    Assert::same(filesize($key), 32);
                    Assert::false(hash_equals(hash_file('sha256', $key), $hash), 'changed valid-length key is never accepted');
                } else { Assert::same(file_get_contents($key), $kind === 'corrupt' ? 'invalid' : ''); }
                $lock = new WorkerLock();
                Assert::true($lock->acquire($db)); $lock->close();
            } finally { $child?->close(); $server?->close(); unset($other, $lock, $child, $server, $db); $directory->close(); }
        }
    }

    #[Test]
    public function originalKeyFailureSurvivesReadonlyFinalizationAndNextPassRefusesRemovedKey(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-finalization-r1-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            self::sites($db, $directory, $server->base);
            $key = $directory->path . '/github-token.key';
            $worker = new PeriodicWorker($db, new WorkerHttp($server->base), output: static function (string $message) use ($db, $key): void {
                if ($message === 'Worker started.') { $db->exec('PRAGMA query_only = ON'); rename($key, $key . '.held'); }
            });
            $error = null;
            try { $worker->run(); } catch (\Throwable $caught) { $error = $caught; }
            Assert::instanceOf($error, SharedKeyFailure::class, 'cleanup PDO error must not replace original fatal semantics');
            Assert::same($error->getPrevious(), null);
            Assert::same(self::requests($directory), []);
            $lock = new WorkerLock(); Assert::true($lock->acquire($db)); $lock->close();
            $db->exec('PRAGMA query_only = OFF');
            rename($key . '.held', $key);
            $worker = new PeriodicWorker($db, new WorkerHttp($server->base), output: static function (): void {});
            $worker->runPass();
            $before = self::requests($directory);
            Assert::same(count($before), 2, 'individual malformed credentials still continue with valid shared key');
            rename($key, $key . '.held');
            try { $worker->runPass(); Assert::true(false); } catch (SharedKeyFailure) {}
            Assert::same(self::requests($directory), $before, 'next pass refuses before another request');
        } finally { unset($caught, $error, $lock, $worker, $db); gc_collect_cycles(); $server?->close(); $directory->close(); }
    }

    private static function sites(\PDO $db, TemporaryDirectory $directory, string $base, string $health = '/up'): void
    {
        (new TokenVault($directory->path . '/github-token.key'))->encrypt(bin2hex(random_bytes(24)));
        $sites = new SiteRepository($db);
        for ($i = 0; $i < 2; ++$i) {
            $sites->save(array_replace(UnitFixtures::site(), ['url' => $base, 'health_path' => $health,
                'version_path' => '', 'repository' => 'fixture/project' . $i]));
        }
        $db->exec("UPDATE sites SET github_token = 'one-damaged-individual-ciphertext'");
    }

    #[Test]
    public function realMetricCooldownWriteAndCredentialLivenessPropagateSharedFailures(): void
    {
        foreach (['readonly', 'key'] as $kind) {
            $directory = new TemporaryDirectory('tablo-worker-callback-r1-');
            $server = null;
            try {
                $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                    environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
                $db = Database::connect($directory->path . '/db.sqlite');
                $sites = new SiteRepository($db);
                $key = $directory->path . '/github-token.key';
                for ($i = 0; $i < 2; ++$i) {
                    $sites->save(array_replace(UnitFixtures::site(), ['url' => $server->base, 'version_path' => '',
                        'repository' => $kind === 'readonly' ? 'fixture/core-limited' : 'fixture/control',
                        'github_token' => bin2hex(random_bytes(24))]));
                }
                $http = new class($db, $server->base, $key, $kind) extends \Tablo\HttpClient {
                    private readonly \Tablo\HttpClient $real;
                    public function __construct(private \PDO $db, private string $base, private string $key, private string $kind)
                    { $this->real = new \Tablo\HttpClient(true); }
                    public function get(string $url, array $headers = []): array
                    {
                        $api = str_starts_with($url, 'https://api.github.com');
                        $response = $this->real->get($api ? $this->base . substr($url, strlen('https://api.github.com')) : $url, $headers);
                        if ($api) {
                            if ($this->kind === 'readonly') { $this->db->exec('PRAGMA query_only = ON'); }
                            else { rename($this->key, $this->key . '.held'); }
                        }
                        return $response;
                    }
                };
                $worker = new PeriodicWorker($db, $http, output: static function (): void {});
                $error = null;
                try { $worker->run(); } catch (\Throwable $caught) { $error = $caught; }
                Assert::instanceOf($error, $kind === 'readonly' ? \PDOException::class : SharedKeyFailure::class);
                if ($kind === 'readonly') { Assert::same($error->errorInfo[1], 8, 'real SQLITE_READONLY from nested cooldown persistence'); }
                Assert::same(count(self::requests($directory)), 2, 'health plus first admitted metric; no more fields/site/pass');
                Assert::same((int) $db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL')->fetchColumn(), 0);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
                $lock = new WorkerLock(); Assert::true($lock->acquire($db)); $lock->close();
            } finally { unset($caught, $error, $lock, $worker, $http, $sites, $db); $server?->close(); $directory->close(); }
        }
    }

    private static function requests(TemporaryDirectory $directory): array
    {
        $path = $directory->path . '/requests.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => json_decode($line, true, 16, JSON_THROW_ON_ERROR)['path'],
            file($path, FILE_IGNORE_NEW_LINES)) : [];
    }
}
