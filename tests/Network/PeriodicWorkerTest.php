<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\GitTokenRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class PeriodicWorkerTest
{
    #[Test]
    public function forcedExitMidWorkRetainsCompletedDebtAndRestartReleasesOnlyOwnGeneration(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-midwork-');
        $child = $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $input = array_replace(UnitFixtures::site(), ['url' => $server->base, 'version_path' => '']);
            $first = $sites->save(array_replace($input, ['repository' => 'fixture/first', 'health_path' => '/up']));
            $second = $sites->save(array_replace($input, ['repository' => 'fixture/second', 'health_path' => '/barrier']));
            $child = new WorkerProcess($directory, 'midwork', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'], ['TABLO_DB' => $path,'TABLO_WORKER_BASE' => $server->base]);
            $deadline = microtime(true) + 5;
            while (!is_file($directory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
            Assert::true(is_file($directory->path . '/entered'));
            $state = new WorkerStateRepository($db);
            $old = $state->generation();
            $turns = $state->turns($first, 0);
            Assert::true(min($turns) > 0);
            Assert::true($sites->find($first)['checked_at'] !== null);
            Assert::same($sites->find($second)['checked_at'], null);
            $forced = $child->terminate();
            Assert::false($child->running());
            Assert::true($forced['exit_code'] !== 0);
            Assert::same($state->turns($first, 0), $turns);
            file_put_contents($directory->path . '/release', 'release');
            $child = new WorkerProcess($directory, 'successor', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'], ['TABLO_DB' => $path,'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            Assert::false($state->requestStop($old));
            $state->requestStop($state->generation());
            Assert::same($child->wait()['exit_code'], 0);
            Assert::true($sites->find($second)['checked_at'] !== null);
            Assert::true(min($state->turns($second, 0)) > 0);
        } finally {
            file_put_contents($directory->path . '/release', 'release');
            $child?->close(); $server?->close(); unset($state, $sites, $db); $directory->close();
        }
    }

    #[Test]
    public function realWorkerCompletesCurrentHttpBeforeStopAndRejectsConcurrentCredentialChanges(): void
    {
        foreach (['site-token','saved-token','rename-only','stop'] as $mode) {
            $directory = new TemporaryDirectory('tablo-worker-credential-barrier-');
            $child = $server = null;
            try {
                $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
                $path = $directory->path . '/db.sqlite';
                $db = Database::connect($path);
                $sites = new SiteRepository($db);
                $tokens = new GitTokenRepository($db);
                $token = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
                $input = array_replace(UnitFixtures::site(), ['url' => $server->base, 'repository' => 'fixture/project', 'health_path' => '/barrier',
                    'version_path' => '', 'git_token_id' => $mode === 'site-token' ? '' : $token, 'github_token' => $mode === 'site-token' ? bin2hex(random_bytes(24)) : '']);
                $id = $sites->save($input);
                $child = new WorkerProcess($directory, 'credential', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'], ['TABLO_DB' => $path,'TABLO_WORKER_BASE' => $server->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($directory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
                Assert::true(is_file($directory->path . '/entered'));
                $state = new WorkerStateRepository($db);
                if ($mode === 'site-token') { $sites->save(array_replace($input, ['github_token' => bin2hex(random_bytes(24))]), $id); }
                if ($mode === 'saved-token') { $tokens->save(['name' => 'Shared', 'provider' => 'github','token' => bin2hex(random_bytes(24))], $token); }
                if ($mode === 'rename-only') { $tokens->save(['name' => 'Renamed', 'provider' => 'github','token' => ''], $token); }
                if ($mode === 'stop') { $state->requestStop($state->generation()); Assert::true($child->running(), 'stop request is not immediate exit of admitted HTTP'); }
                file_put_contents($directory->path . '/release', 'release');
                if ($mode !== 'stop') { $child->awaitOutput('Worker pass complete.'); $state->requestStop($state->generation()); }
                Assert::same($child->wait()['exit_code'], 0);
                Assert::same($sites->find($id)['checked_at'] !== null, in_array($mode, ['rename-only','stop'], true));
                Assert::false(str_contains(implode(' ', $child->output()), 'synthetic-private-response'));
            } finally {
                file_put_contents($directory->path . '/release', 'release');
                $child?->close(); $server?->close(); unset($state, $tokens, $sites, $db); $directory->close();
            }
        }
    }

    #[Test]
    public function actualCliLocksCanonicalDatabaseAcrossSourceRootsAndCwdAndRecoversAfterForcedExit(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-cli-');
        $first = $other = $next = null;
        try {
            $root = dirname(__DIR__, 2);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $state = new WorkerStateRepository($db);
            mkdir($directory->path . '/source/bin', 0700, true);
            mkdir($directory->path . '/source/vendor', 0700, true);
            copy($root . '/bin/worker.php', $directory->path . '/source/bin/worker.php');
            file_put_contents($directory->path . '/source/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
            $first = new WorkerProcess($directory, 'first', [PHP_BINARY, $root . '/bin/worker.php'], ['TABLO_DB' => $path]);
            $first->awaitOutput('Worker pass complete.');
            $old = $state->generation();
            $conflict = new WorkerProcess($directory, 'conflict', [PHP_BINARY, $directory->path . '/source/bin/worker.php'],
                ['TABLO_DB' => '../db.sqlite'], $directory->path . '/source');
            Assert::same($conflict->wait()['exit_code'], 2);
            $other = new WorkerProcess($directory, 'other', [PHP_BINARY, $root . '/bin/worker.php'], ['TABLO_DB' => $directory->path . '/other.sqlite']);
            $other->awaitOutput('Worker pass complete.');
            Assert::true($first->running() && $other->running(), 'independent installation positive control');
            $first->terminate();
            Assert::false($first->running());
            Assert::true(is_file($path . '.worker.lock'), 'forced exit keeps stable lock filename');
            $next = new WorkerProcess($directory, 'next', [PHP_BINARY, $root . '/bin/worker.php'], ['TABLO_DB' => $path]);
            $next->awaitOutput('Worker pass complete.');
            $current = $state->generation();
            Assert::true($old !== $current);
            Assert::false($state->requestStop($old), 'stale observed generation cannot stop successor');
            $state->finish($old);
            Assert::same($state->generation(), $current);
            $stop = Subprocess::run([PHP_BINARY, $root . '/bin/worker.php', '--stop'], $directory, ['TABLO_DB' => $path]);
            Assert::same($stop['exit_code'], 0);
            Assert::same($stop['stdout'], "Worker stop requested.\n");
            Assert::same($next->wait()['exit_code'], 0);
            Assert::same($state->generation(), null);
            Assert::true(is_file($path . '.worker.lock'));
            Subprocess::run([PHP_BINARY, $root . '/bin/worker.php', '--stop'], $directory, ['TABLO_DB' => $directory->path . '/other.sqlite']);
            Assert::same($other->wait()['exit_code'], 0);
        } finally {
            $first?->close(); $other?->close(); $next?->close();
            unset($state, $db); $directory->close();
        }
    }

    #[Test]
    public function actualCliRejectsMemoryOpenFailuresMalformedStartupAndInvalidUseWithoutPrivateOutput(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-startup-');
        try {
            $entry = dirname(__DIR__, 2) . '/bin/worker.php';
            foreach ([':memory:', $directory->path . '/synthetic-private-db.sqlite'] as $path) {
                if ($path !== ':memory:') {
                    $db = Database::connect($path);
                    mkdir($path . '.worker.lock');
                    unset($db);
                }
                $result = Subprocess::run([PHP_BINARY, $entry], $directory, ['TABLO_DB' => $path]);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stdout'], '');
                Assert::same($result['stderr'], "Worker failed. Check installation configuration and local storage.\n");
            }
            Assert::same(Subprocess::run([PHP_BINARY, $entry, '--invalid'], $directory, ['TABLO_DB' => ':memory:'])['exit_code'], 64);
        } finally { unset($db); $directory->close(); }
    }

    #[Test]
    public function publicWorkerReloadsEnabledIdsAndRejectsInFlightAbaWithRealHttpBarrier(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-barrier-');
        $child = $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $input = array_replace(UnitFixtures::site(), ['url' => $server->base, 'repository' => 'example/project', 'version_path' => '']);
            $first = $sites->save(array_replace($input, ['health_path' => '/barrier']));
            $later = $sites->save(array_replace($input, ['health_path' => '/later']));
            $deleted = $sites->save(array_replace($input, ['health_path' => '/deleted']));
            $child = new WorkerProcess($directory, 'barrier', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
                ['TABLO_DB' => $path, 'TABLO_WORKER_BASE' => $server->base]);
            $deadline = microtime(true) + 5;
            while (!is_file($directory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
            Assert::true(is_file($directory->path . '/entered'));
            $sites->save(array_replace($input, ['health_path' => '/barrier', 'enabled' => 0]), $first);
            $sites->save(array_replace($input, ['health_path' => '/barrier']), $first);
            $sites->save(array_replace($input, ['health_path' => '/later', 'enabled' => 0]), $later);
            $sites->delete($deleted);
            $new = $sites->save(array_replace($input, ['health_path' => '/new']));
            file_put_contents($directory->path . '/release', 'release');
            $child->awaitOutput('Worker pass complete.');
            $state = new WorkerStateRepository($db);
            Assert::true($state->requestStop($state->generation()));
            Assert::same($child->wait()['exit_code'], 0);
            Assert::same($sites->find($first)['checked_at'], null);
            Assert::same($sites->find($later)['checked_at'], null);
            Assert::same($sites->find($new)['checked_at'], null, 'finite ID list admits new row next pass');
            $requests = file_get_contents($directory->path . '/requests.jsonl');
            Assert::false(str_contains($requests, '/later') || str_contains($requests, '/deleted') || str_contains($requests, '/new'));
            $child = new WorkerProcess($directory, 'restart', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
                ['TABLO_DB' => $path, 'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            $state->requestStop($state->generation());
            Assert::same($child->wait()['exit_code'], 0);
            Assert::same($sites->find($new)['online'], 1);
            Assert::true($sites->find($new)['checked_at'] !== null);
        } finally {
            if (is_dir($directory->path)) { file_put_contents($directory->path . '/release', 'release'); }
            $child?->close(); $server?->close(); unset($state, $sites, $db); $directory->close();
        }
    }
}
