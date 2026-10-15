<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerProcessCustodyTest
{

    #[Test]
    public function forcedExitMidWorkRetainsCompletedDebtAndRestartReleasesOnlyOwnGeneration(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-midwork-');
        $server = null; $child = null;
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
            while (!is_file($directory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20_000); }
            Assert::true(is_file($directory->path . '/entered'));
            $state = new WorkerStateRepository($db);
            $old = $state->generation();
            $turns = $state->turns($first, 0);
            Assert::true(min($turns) > 0);
            Assert::true(\Tablo\Tests\Support\FixtureValue::row($sites->find($first))['checked_at'] !== null);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($second))['checked_at'], null);
            $forced = $child->terminate();
            Assert::false($child->running());
            Assert::true($forced['exit_code'] !== 0);
            Assert::same($state->turns($first, 0), $turns);
            file_put_contents($directory->path . '/release', data: 'release');
            $child = new WorkerProcess($directory, 'successor', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'], ['TABLO_DB' => $path,'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            Assert::false($state->requestStop(\Tablo\Tests\Support\FixtureValue::string($old)));
            $state->requestStop(\Tablo\Tests\Support\FixtureValue::string($state->generation()));
            Assert::same($child->wait()['exit_code'], 0);
            Assert::true(\Tablo\Tests\Support\FixtureValue::row($sites->find($second))['checked_at'] !== null);
            Assert::true(min($state->turns($second, 0)) > 0);
        } finally {
            file_put_contents($directory->path . '/release', data: 'release');
            $child?->close(); $server?->close(); unset($state, $sites, $db); $directory->close();
        }
    }



    #[Test]
    public function actualCliLocksCanonicalDatabaseAcrossSourceRootsAndCwdAndRecoversAfterForcedExit(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-cli-');
        $next = null; $other = null; $first = null;
        try {
            $root = dirname(__DIR__, levels: 2);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path);
            $state = new WorkerStateRepository($db);
            mkdir($directory->path . '/source/bin', permissions: 0o700, recursive: true);
            mkdir($directory->path . '/source/vendor', permissions: 0o700, recursive: true);
            copy($root . '/bin/worker.php', $directory->path . '/source/bin/worker.php');
            mkdir($directory->path . '/source/app', permissions: 0o700);
            copy($root . '/app/RuntimeIni.php', $directory->path . '/source/app/RuntimeIni.php');
            file_put_contents($directory->path . '/source/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', return: true) . ';');
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
            Assert::false($state->requestStop(\Tablo\Tests\Support\FixtureValue::string($old)), 'stale observed generation cannot stop successor');
            $state->finish(\Tablo\Tests\Support\FixtureValue::string($old));
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
}
