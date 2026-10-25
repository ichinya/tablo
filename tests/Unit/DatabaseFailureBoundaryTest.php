<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\DatabaseFailure;
use Tablo\GitCooldownLedger;
use Tablo\GitCredentialLiveProof;
use Tablo\GitCredentialResolver;
use Tablo\GitFieldCheck;
use Tablo\GitHubClock;
use Tablo\GitHubConnection;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubCredential;
use Tablo\GitHubMetric;
use Tablo\GitHubRequestPolicy;
use Tablo\GitResolvedCredential;
use Tablo\GitTokenRepository;
use Tablo\SchemaReference;
use Tablo\SiteRepository;
use Tablo\ValidationException;
use Tablo\WorkerSiteCheck;
use Tablo\WorkerSitePriority;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class DatabaseFailureBoundaryTest
{
    #[Test]
    public function workerOuterInnerAndPriorityStorageFailuresEscapeWithoutSettlement(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-database-failure-');
        try {
            $db = self::database($directory);
            $vault = new \Tablo\TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $id = $sites->save([...UnitFixtures::site(), 'version_path' => '']);
            $state = new WorkerStateRepository($db, $sites);
            $http = new FakeHttp([]);
            $connection = new GitHubConnection($sites, $http, worker: true);
            $messages = [];
            $worker = new WorkerSiteCheck($sites, $state, $http, $vault, static function (string $message) use (&$messages): void { $messages[] = $message; });
            foreach (['SELECT s.*', 'FROM worker_progress'] as $fragment) {
                $failure = new DatabaseFailure('unavailable');
                $db->arm($failure, $fragment);
                self::sameFailure(static fn () => $worker->check($id, $connection), $failure);
                $db->disarm();
                Assert::same($messages, []); Assert::same($http->requests, []);
                Assert::same(FixtureValue::row($sites->find($id))['checked_at'], null);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM worker_progress'))->fetchColumn(), 0);
            }
            $failure = new DatabaseFailure('busy'); $db->arm($failure, 'FROM worker_progress');
            self::sameFailure(static fn () => (new WorkerSitePriority($sites, $state))->ordered($connection), $failure);
            $db->disarm(); Assert::same($messages, []); Assert::same($http->requests, []);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $worker = null; $connection = null; $sites = null; $state = null; $vault = null; $db = null; $directory->close(); }
    }

    #[Test]
    public function credentialResolverFlagsAndLiveProofPreserveTypedFailures(): void
    {
        $directory = new TemporaryDirectory('tablo-credential-database-failure-');
        try {
            $db = self::database($directory); $vault = new \Tablo\TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $token = (new GitTokenRepository($db, $vault))->save(['name' => 'Synthetic', 'provider' => 'github', 'token' => str_repeat('s', times: 32)]);
            $id = $sites->save([...UnitFixtures::site(), 'git_token_id' => $token]);
            $site = FixtureValue::row($sites->find($id));
            $failure = new DatabaseFailure('schema'); $db->arm($failure, 'SELECT id, name, provider');
            foreach ([[true, false], [false, true]] as [$worker, $propagate]) {
                self::sameFailure(static fn () => (new GitCredentialResolver($sites, $worker, $propagate))->resolve($site, []), $failure);
            }
            $validation = null;
            try { (new GitCredentialResolver($sites, false, false))->resolve($site, []); } catch (ValidationException $error) { $validation = $error; }
            Assert::true($validation instanceof ValidationException);
            $proof = new GitResolvedCredential('', '', 'anonymous', null);
            self::sameFailure(static fn () => GitCredentialLiveProof::matches($sites, [], $site, $proof), $failure);
            $db->disarm();
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $site = null; $sites = null; $vault = null; $db = null; $directory->close(); }
    }

    #[Test]
    public function cooldownPolicyLedgerMetricAndFieldKeepFatalIdentity(): void
    {
        $directory = new TemporaryDirectory('tablo-git-database-failure-');
        try {
            $db = self::database($directory); $storage = new GitHubCooldownRepository($db);
            $failure = new DatabaseFailure('unavailable');
            $db->arm($failure, 'SELECT MAX');
            $policy = new GitHubRequestPolicy($storage);
            self::sameFailure(static fn () => $policy->before('core', PHP_INT_MAX), $failure);
            Assert::same($policy->admissions(), 0);
            $db->arm($failure, 'INSERT INTO github_cooldowns');
            self::sameFailure(static fn () => (new GitCooldownLedger($storage, new GitHubClock()))->defer('core', 1234, 'anonymous'), $failure);
            $policy = new GitHubRequestPolicy();
            $metric = new GitHubMetric($policy, new GitHubCredential(current: static fn (): bool => throw $failure), 'identity');
            self::sameFailure(static fn () => $metric->metric('release', static fn (): string => 'unused'), $failure);
            $state = \Tablo\CheckState::initial(UnitFixtures::site()); $errors = [];
            self::sameFailure(static function () use ($failure, &$state, &$errors): void {
                (new GitFieldCheck(new FakeProvider()))->check('latest_release', static fn (): string => throw $failure, null, $state, $errors);
            }, $failure);
            Assert::same($errors, []); Assert::same($state['latest_release'], null); $db->disarm();
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $metric = null; $policy = null; $storage = null; $db = null; $directory->close(); }
    }

    #[Test]
    public function actualWorkerPassStopsAtNestedCooldownPersistenceBeforeAnotherFieldOrSite(): void
    {
        $directory = new TemporaryDirectory('tablo-worker-cooldown-database-failure-');
        try {
            $db = self::database($directory); $vault = new \Tablo\TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            for ($index = 0; $index < 2; ++$index) { $sites->save([...UnitFixtures::site(), 'name' => 'Synthetic ' . $index, 'version_path' => '']); }
            $failure = new DatabaseFailure('unavailable'); $db->arm($failure, 'INSERT INTO github_cooldowns');
            $http = new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, '{"tag_name":"v1"}') + ['headers' => [
                'x-ratelimit-resource' => 'core', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (time() + 3600)]]]);
            $messages = [];
            $worker = new \Tablo\PeriodicWorker($db, $http, new \Tablo\WorkerHooks(output: static function (string $message) use (&$messages): void { $messages[] = $message; }), $vault);
            self::sameFailure($worker->runPass(...), $failure);
            $db->disarm();
            Assert::same(count($http->requests), 2); Assert::same($messages, []);
            foreach (['sites WHERE checked_at IS NOT NULL', 'worker_progress', 'github_cooldowns'] as $table) {
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM ' . $table))->fetchColumn(), 0);
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $worker = null; $sites = null; $vault = null; $db = null; $directory->close(); }
    }

    private static function database(TemporaryDirectory $directory): FailureBoundaryPdo
    {
        $db = new FailureBoundaryPdo($directory->path . '/database.sqlite');
        SchemaReference::reference(10, ['endpoint' => true], $db); $db->exec('PRAGMA main.user_version = 10');
        return $db;
    }

    /** @param \Closure(): mixed $operation */
    private static function sameFailure(\Closure $operation, DatabaseFailure $failure): void
    {
        $caught = null;
        try { $operation(); } catch (\Throwable $error) { $caught = $error; }
        Assert::same($caught, $failure);
    }
}
