<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerAbaAdmissionTest
{




    #[Test]
    public function publicWorkerReloadsEnabledIdsAndRejectsInFlightAbaWithRealHttpBarrier(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-barrier-');
        $server = null; $child = null;
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
            \Tablo\Tests\Support\BarrierSignal::await($directory->path . '/entered');
            $sites->save(array_replace($input, ['health_path' => '/barrier', 'enabled' => 0]), $first);
            $sites->save(array_replace($input, ['health_path' => '/barrier']), $first);
            $sites->save(array_replace($input, ['health_path' => '/later', 'enabled' => 0]), $later);
            $sites->delete($deleted);
            $new = $sites->save(array_replace($input, ['health_path' => '/new']));
            file_put_contents($directory->path . '/release', data: 'release');
            $child->awaitOutput('Worker pass complete.');
            $state = new WorkerStateRepository($db);
            Assert::true($state->requestStop($state->generation()));
            Assert::same($child->wait()['exit_code'], 0);
            (new WorkerAbaAssertions($sites, $directory))->firstPass([$first, $later, $new]);
            $child = new WorkerProcess($directory, 'restart', [PHP_BINARY, dirname(__DIR__) . '/fixtures/worker-process.php'],
                ['TABLO_DB' => $path, 'TABLO_WORKER_BASE' => $server->base]);
            $child->awaitOutput('Worker pass complete.');
            $state->requestStop($state->generation());
            Assert::same($child->wait()['exit_code'], 0);
            Assert::same($sites->find($new)['online'], 1);
            Assert::true($sites->find($new)['checked_at'] !== null);
        } finally {
            if (is_dir($directory->path)) { file_put_contents($directory->path . '/release', data: 'release'); }
            $child?->close(); $server?->close(); unset($state, $sites, $db); $directory->close();
        }
    }
}
