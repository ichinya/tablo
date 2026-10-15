<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Auth;
use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\PeriodicWorker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class ExternalKeyTest
{
    #[Test]
    public function actualCommandsRefuseInvalidStartupButStopAndPasswordResetRemainIndependent(): void
    {
        $directory = new TemporaryDirectory('tablo-external-cli-');
        $child = null; $state = null; $db = null;
        try {
            $path = $directory->path . '/db.sqlite';
            $key = $directory->path . '/key'; file_put_contents($key, random_bytes(32));
            $env = ['TABLO_DB' => $path, 'TABLO_TOKEN_KEY_FILE' => $key];
            foreach (['bin/check.php','bin/worker.php'] as $script) {
                $result = Subprocess::run([PHP_BINARY, $script], $directory,
                    ['TABLO_DB' => $directory->path . '/absent/new.sqlite','TABLO_TOKEN_KEY_FILE' => $directory->path . '/missing']);
                Assert::same($result['exit_code'], 1); Assert::false(is_dir($directory->path . '/absent'));
                Assert::false(str_contains($result['stdout'] . $result['stderr'], $directory->path));
            }
            $db = Database::connect($path);
            (new Auth($db))->setup('synthetic-password','synthetic-password');
            $child = new WorkerProcess($directory,'live',[PHP_BINARY,'bin/worker.php'],$env);
            $child->awaitOutput('Worker pass complete.');
            $preflight=Subprocess::run([PHP_BINARY,'bin/key-preflight.php'],$directory,$env);
            Assert::same($preflight['exit_code'],0); Assert::false(str_contains($preflight['stdout'],$key));
            $generation = (new WorkerStateRepository($db))->generation(); Assert::true(is_string($generation));
            unlink($key);
            $result = Subprocess::run([PHP_BINARY,'bin/worker.php','--stop'],$directory,$env);
            Assert::same($result['exit_code'],0); Assert::same($result['stdout'],"Worker stop requested.\n");
            Assert::same($child->wait()['exit_code'],0);
            Assert::same((new WorkerStateRepository($db))->generation(),null);
            $result = Subprocess::run(command: [PHP_BINARY,'bin/admin-password.php','--password-stdin'], directory: $directory, environment: $env, options: new \Tablo\Tests\Support\CommandOptions(input: \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-password')));
            Assert::same($result['exit_code'],0);
            Assert::true(password_verify(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-password'),$db->query('SELECT password_hash FROM users')->fetchColumn()));
            Assert::false(is_file($key)); Assert::false(is_file($directory->path . '/github-token.key'));
            $preflight=Subprocess::run([PHP_BINARY,'bin/key-preflight.php'],$directory,$env); Assert::same($preflight['exit_code'],1);
            $result = Subprocess::run([PHP_BINARY,'bin/check.php'],$directory,$env); Assert::same($result['exit_code'],1);
            $result = Subprocess::run([PHP_BINARY,'bin/worker.php','--stop'],$directory,
                ['TABLO_DB'=>$directory->path.'/never.sqlite','TABLO_TOKEN_KEY_FILE'=>$key]);
            Assert::same($result['exit_code'],1); Assert::false(is_file($directory->path.'/never.sqlite'));
        } finally { $child?->close(); $db = null; $state = null; $child = null; $directory->close(); }
    }

    #[Test]
    public function realQuotaAdmissionSurvivesExactByteTransferWorkerPassAndCleanBackupRestore(): void
    {
        $directory = new TemporaryDirectory('tablo-external-quota-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $worker = null; $connection = null; $vault = null; $sites = null; $db = null; $server = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            $server = new TestServer($directory,dirname(__DIR__).'/fixtures/github-budget-router.php');
            [$path, $db, $vault, $sites, $secret, $http, $connection, $before, $cipher] = (new ExternalQuotaFixture($directory, $server))->create();
            $db = null; $vault = null; $sites = null; $connection = null;
            // Stopped writers: SQLite backup reads the complete logical WAL state.
            ExternalBackupFixture::transfer($directory, $path);
            $db=Database::connect($path); $vault=TokenVault::forDatabase($db); $sites=new SiteRepository($db,$vault);
            $http=new MeasuredGitHubHttp($server->base); $connection=new GitHubConnection($sites,$http);
            foreach ([['github_token'=>$secret],['git_token_id'=>'1']] as $input) {
                try { $connection->provider(null,$input)->getLatestRelease('fixture/primary'); }
                catch (GitHubFailure $error) { Assert::same($error->reason,'rate-limit'); unset($error); }
            }
            $worker=new PeriodicWorker($db, new \Tablo\HttpClient(true), hooks: new \Tablo\WorkerHooks(connection:static fn()=>new GitHubConnection($sites,$http,worker:true), output:static function(string $message):void{}), vault:$vault);
            $worker->runPass(); Assert::same($http->core,0); Assert::same($http->search,2, 'core cooldown does not deny independent search admission');
            (new ExternalQuotaAssertions($db))->preserved($before, $cipher);
            $db = null; $vault = null; $sites = null; $connection = null; $worker = null;
            (new ExternalRestoreAssertions($directory))->restored($before, $cipher, $secret);
        } finally {
            $db = null; $vault = null; $sites = null; $connection = null; $worker = null; gc_collect_cycles(); $server?->close();
            putenv($environment===false?'TABLO_TOKEN_KEY_FILE':'TABLO_TOKEN_KEY_FILE='.$environment); $directory->close();
        }
    }

    #[Test]
    public function actualWorkerLiveKeyLossAtNetworkBoundaryStopsBeforeSettlement(): void
    {
        foreach (['missing','bad','different'] as $kind) {
            $directory=new TemporaryDirectory('tablo-external-worker-'); $child = null; $db = null; $server = null;
            try {
                $server=new TestServer($directory,dirname(__DIR__).'/fixtures/worker-router.php',environment:['TABLO_WORKER_FIXTURE'=>$directory->path]);
                $path=$directory->path.'/db.sqlite'; $db=Database::connect($path); $vault=TokenVault::forDatabase($db);
                $sites=new SiteRepository($db,$vault);
                $sites->save(array_replace(UnitFixtures::site(),['url'=>$server->base,'health_path'=>'/barrier','version_path'=>'','github_token'=>bin2hex(random_bytes(24))]));
                $key=$directory->path.'/external'; rename($directory->path.'/github-token.key',$key); $vault = null; $sites = null;
                $child=new WorkerProcess($directory,$kind,[PHP_BINARY,'bin/worker.php'],['TABLO_DB'=>$path,'TABLO_TOKEN_KEY_FILE'=>$key,'TABLO_ALLOW_PRIVATE_NETWORK'=>'1']);
                $deadline=microtime(true)+5;
                while(!is_file($directory->path.'/entered') && $child->running() && microtime(true)<$deadline){clearstatcache();usleep(20_000);}
                Assert::true(is_file($directory->path.'/entered'));
                rename($key,$key.'.original');
                if($kind!=='missing'){file_put_contents($key,$kind==='bad'?'bad':random_bytes(32));}
                file_put_contents($directory->path.'/release',data: 'ready');
                $result=$child->wait(); Assert::same($result['exit_code'],1);
                Assert::false(str_contains($result['stdout'],'Worker pass complete.'));
                Assert::false(str_contains($result['stderr'],$key));
                Assert::same((int)$db->query('SELECT count(*) FROM sites WHERE checked_at IS NOT NULL')->fetchColumn(),0);
                Assert::same((int)$db->query('SELECT count(*) FROM worker_progress')->fetchColumn(),0);
                Assert::false(is_file($directory->path.'/github-token.key'));
            } finally {$child?->close();$server?->close();$vault = null; $sites = null; $db = null; $server = null; $child = null;$directory->close();}
        }
    }
}
