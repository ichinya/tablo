<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\PeriodicWorker;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\WorkerLock;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerMetricFailureTest extends WorkerFailureFixtureCase
{


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
                    public function getEndpoint(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [],
                        int $maxRedirects = 3, ?int $deadline = null): array
                    { return $this->real->getEndpoint($url, $headers, $maxRedirects, $deadline); }
                    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
                    {
                        $api = str_starts_with($url, 'https://api.github.com');
                        $response = $this->real->get($api ? $this->base . substr($url, strlen('https://api.github.com')) : $url, $headers);
                        if ($api) {
                            switch (true) {
case ($this->kind === 'readonly'):
 $this->db->exec('PRAGMA query_only = ON');
    break;
default:
 rename($this->key, $this->key . '.held');
    break;
}
                        }
                        return $response;
                    }
                };
                $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(output: static function (): void {}));
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
}
