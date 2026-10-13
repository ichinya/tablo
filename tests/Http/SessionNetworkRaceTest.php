<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SettingsRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class SessionNetworkRaceTest
{
    #[Test]
    public function independentHttpPeersAcquireReleasedSessionAndStaleNetworkResultsNeverReviveAuthority(): void
    {
        foreach (['navigation', 'logout', 'idle', 'absolute', 'password', 'login', 'decrease', 'rollback'] as $race) {
            $peerDirectory = new TemporaryDirectory('tablo-session-barrier-');
            $peer = new TestServer($peerDirectory, dirname(__DIR__) . '/endpoint-router.php', environment: ['TABLO_HEALTH_FIXTURE' => $peerDirectory->path]);
            $fixture = new SessionFixture(true); $web = $fixture->web;
            $secondDirectory = new TemporaryDirectory('tablo-session-independent-');
            $second = null; $child = null;
            try {
                $csrf = $web->authenticate();
                (new SettingsRepository($web->database()))->updateIdleTimeout($race === 'absolute' ? '720' : '2');
                SqlStatement::checked($web->database()->prepare("INSERT INTO sites(id,name,url,repository,enabled,health_path,version_path)
                    VALUES(1,'Barrier control',?,'fixture/public',1,'','')"))->execute([$peer->base . '/barrier/']);
                $second = new TestServer($secondDirectory, dirname(__DIR__) . '/web-router.php', dirname(__DIR__, levels: 2) . '/public', [
                    'TABLO_DB' => $web->directory->path . '/test.sqlite', 'TABLO_TEST_RUNTIME' => $web->directory->path . '/runtime',
                    'TABLO_TEST_SESSION_CLOCK' => '1', 'TABLO_TEST_GITHUB_LOG' => $web->directory->path . '/github-calls.log',
                    'TABLO_TEST_TRUSTED_PROXIES' => '', 'TABLO_TRUSTED_PROXIES' => '', 'TABLO_COOKIE_SECURE' => '0',
                    'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'GITHUB_TOKEN' => FixtureCredentials::value('fixture-token'),
                ]);
                $options = new WebRequestOptions(base: $second->base);
                $action = new SessionConcurrentAction($fixture, $second->base);
                $action->prepare($race);
                $child = new Subprocess([PHP_BINARY, dirname(__DIR__) . '/fixtures/session-request.php',
                    $web->server->base . '/sites/1/check', $web->directory->path . '/cookies'], $web->directory);
                $child->send(json_encode(['_csrf' => $csrf], JSON_THROW_ON_ERROR));
                $deadline = microtime(true) + 5;
                while (!is_file($peerDirectory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
                Assert::true(is_file($peerDirectory->path . '/entered'), $race . ': real network barrier entered');
                $action->run($race, $csrf);
                Assert::false(is_file($peerDirectory->path . '/release'), 'peer remained blocked throughout concurrent action');
                file_put_contents($peerDirectory->path . '/release', data: 'release');
                $result = $child->finish(); $child = null;
                (new SessionRaceAssertions($fixture, $options))->result($race, $result);
            } catch (\Throwable $error) { $web->directory->retainFailure($error); $peerDirectory->retainFailure($error); $secondDirectory->retainFailure($error); throw $error; }
            finally {
                file_put_contents($peerDirectory->path . '/release', data: 'release');
                $child?->close(); $second?->close(); $fixture->close(); $peer->close();
                $secondDirectory->close(); $peerDirectory->close();
            }
        }
    }
}
