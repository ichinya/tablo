<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\GitTokenRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class PeriodicWorkerTest
{



    #[Test]
    public function realWorkerCompletesCurrentHttpBeforeStopAndRejectsConcurrentCredentialChanges(): void
    {
        foreach (['site-token','saved-token','rename-only','stop'] as $mode) {
            $directory = new TemporaryDirectory('tablo-worker-credential-barrier-');
            $server = null; $child = null;
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
                \Tablo\Tests\Support\BarrierSignal::await($directory->path . '/entered');
                $state = new WorkerStateRepository($db);
                if ($mode === 'site-token') { $sites->save(array_replace($input, ['github_token' => bin2hex(random_bytes(24))]), $id); }
                if ($mode === 'saved-token') { $tokens->save(['name' => 'Shared', 'provider' => 'github','token' => bin2hex(random_bytes(24))], $token); }
                if ($mode === 'rename-only') { $tokens->save(['name' => 'Renamed', 'provider' => 'github','token' => ''], $token); }
                if ($mode === 'stop') { $state->requestStop($state->generation()); Assert::true($child->running(), 'stop request is not immediate exit of admitted HTTP'); }
                file_put_contents($directory->path . '/release', data: 'release');
                if ($mode !== 'stop') { $child->awaitOutput('Worker pass complete.'); $state->requestStop($state->generation()); }
                Assert::same($child->wait()['exit_code'], 0);
                WorkerCredentialAssertions::settled(WorkerCredentialObservation::read($sites, $db, $id), $mode);
                Assert::false(str_contains(implode(' ', $child->output()), 'synthetic-private-response'));
            } finally {
                file_put_contents($directory->path . '/release', data: 'release');
                $child?->close(); $server?->close(); unset($state, $tokens, $sites, $db); $directory->close();
            }
        }
    }
}
