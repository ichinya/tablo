<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubRequestPolicy;
use Tablo\GitTokenRepository;
use Tablo\PeriodicWorker;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WorkerHttp;
use Testo\Assert;
use Testo\Test;

final class WorkerFairnessTest
{

    #[Test]
    public function mixedPrimaryAndSharedSecondaryScopesKeepAllHealthAndTypedAdmissionCredit(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-fair-scopes-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php', environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $tokens = new GitTokenRepository($db);
            foreach (['core-limited','healthy','another'] as $name) {
                $token = $tokens->save(['name' => $name, 'provider' => 'github', 'token' => bin2hex(random_bytes(24))]);
                $sites->save(array_replace(SiteInput::defaults(), ['name' => $name, 'url' => $server->base,
                    'repository' => 'fixture/' . $name, 'git_token_id' => $token, 'version_path' => '/version']));
            }
            $http = new WorkerHttp($server->base);
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db));
            $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(connection: static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {}));
            $worker->runPass();
            Assert::same(count(array_filter($http->calls, static fn (string $path): bool => str_starts_with($path, '/repos/fixture/core-limited/'))), 1);
            foreach ([2,3] as $id) { Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['latest_release'], 'v1'); }
            $turns = (new WorkerStateRepository($db))->turns(1, 0);
            Assert::true($turns['latest_release'] > 0);
            Assert::same($turns['latest_commit'], 0, 'pre-admission primary cooldown earns no credit');
            foreach ($sites->all() as $site) { Assert::same($site['online'], 1); Assert::same($site['deployed_version'], 'v1'); }
            $input = array_replace(SiteInput::defaults(), ['name' => 'Secondary', 'url' => $server->base,
                'repository' => 'fixture/secondary-limited', 'version_path' => '/version', 'git_token_id' => 1]);
            $sites->save($input, 1);
            $db->exec('DELETE FROM github_cooldowns');
            $start = count($http->calls);
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db));
            $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(connection: static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {}));
            $worker->runPass();
            Assert::same($policy->admissions(), 1, 'one shared secondary rejection blocks all later GitHub scopes');
            Assert::same(count(array_filter(array_slice($http->calls, $start), static fn (string $path): bool => $path === '/up')), 3);
            foreach ($sites->all() as $site) { Assert::same($site['online'], 1); Assert::same($site['deployed_version'], 'v1'); }
            $start = count($http->calls);
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($db));
            $worker = new PeriodicWorker($db, $http, hooks: new \Tablo\WorkerHooks(connection: static fn (): GitHubConnection => new GitHubConnection($sites, $http, $policy), output: static function (): void {}));
            $worker->runPass();
            Assert::same($policy->admissions(), 0);
            Assert::same(count(array_slice($http->calls, $start)), 6, 'cooled scopes still perform health and version');
        } finally { unset($worker, $policy, $tokens, $sites, $db, $http); $server?->close(); $directory->close(); }
    }
}
