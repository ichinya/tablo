<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SqlStatement;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class SessionCookieRaceTest
{
    #[Test]
    public function actualSharedCookieJarPreservesNewLoginSidCsrfAndFileAfterOldNetworkResponse(): void
    {
        $peerDirectory = new TemporaryDirectory('tablo-cookie-barrier-');
        $secondDirectory = new TemporaryDirectory('tablo-cookie-independent-');
        $fixture = new SessionFixture(true); $web = $fixture->web;
        $peer = null; $second = null; $child = null;
        try {
            $csrf = $web->authenticate();
            $peer = new TestServer($peerDirectory, dirname(__DIR__) . '/endpoint-router.php', environment: ['TABLO_HEALTH_FIXTURE' => $peerDirectory->path]);
            $second = self::second($fixture, $secondDirectory);
            SqlStatement::checked($web->database()->prepare("INSERT INTO sites(id,name,url,repository,enabled,health_path,version_path)
                VALUES(1,'Cookie barrier control',?,'fixture/public',1,'','')"))->execute([$peer->base . '/barrier/']);
            $child = new Subprocess([PHP_BINARY, dirname(__DIR__) . '/fixtures/session-cookie-client.php'], $web->directory);
            $child->send(json_encode(['base' => $web->server->base, 'second' => $second->base, 'jar' => $web->directory->path . '/cookies',
                'barrier' => $peerDirectory->path, 'clock' => $web->directory->path . '/runtime-clock', 'csrf' => $csrf], JSON_THROW_ON_ERROR));
            self::awaitNewCookie($peerDirectory);
            $newFile = $fixture->sessionFile();
            $newBytes = \Tablo\RequiredFile::bytes(file_get_contents($newFile));
            Assert::false(str_contains($newBytes, 'csrf|' . serialize($csrf)), 'new login rotated CSRF');
            file_put_contents($peerDirectory->path . '/release', data: 'release');
            $result = $child->finish(); $child = null;
            \Tablo\Tests\Support\SessionEvidence::rawFile($peerDirectory->path . '/manual-response.headers', label: 'cookie-response');
            \Tablo\Tests\Support\SessionEvidence::record('cookie-jar', ['fixture' => $web->directory->path,
                'sessionFileBefore' => hash('sha256', $newFile), 'sessionFileAfter' => hash('sha256', $fixture->sessionFile()),
                'beforeSha256' => hash('sha256', $newBytes), 'afterSha256' => hash_file('sha256', $newFile)]);
            Assert::same($result['exit_code'], 0);
            $response = \Tablo\JsonRecord::decode($result['stdout']);
            Assert::same(FixtureValue::at($response, 'staleSessionCookieIssued'), false);
            Assert::same(FixtureValue::at($response, 'cachePolicyPreserved'), true);
            Assert::true($fixture->sessionFile() === $newFile, 'actual shared cookie engine kept the new SID after applying old response');
            Assert::true(\Tablo\RequiredFile::bytes(file_get_contents($newFile)) === $newBytes, 'old response cannot alter/delete the live new session');
            Assert::same($web->request('/settings', options: new WebRequestOptions(base: $second->base))['status'], 200);
            Assert::same($fixture->timestamps()['authenticated_at'], SessionFixture::START + 1);
            Assert::same((int) SqlStatement::checked($web->database()->query('SELECT count(*) FROM check_history'))->fetchColumn(), 0);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); $peerDirectory->retainFailure($error); $secondDirectory->retainFailure($error); throw $error; }
        finally {
            file_put_contents($peerDirectory->path . '/release', data: 'release');
            $child?->close(); $second?->close(); $peer?->close(); $fixture->close();
            $secondDirectory->close(); $peerDirectory->close();
        }
    }

    private static function second(SessionFixture $fixture, TemporaryDirectory $directory): TestServer
    {
        $web = $fixture->web;
        return new TestServer($directory, dirname(__DIR__) . '/web-router.php', dirname(__DIR__, levels: 2) . '/public', [
            'TABLO_DB' => $web->directory->path . '/test.sqlite', 'TABLO_TEST_RUNTIME' => $web->directory->path . '/runtime',
            'TABLO_TEST_SESSION_CLOCK' => '1', 'TABLO_TEST_TRUSTED_PROXIES' => '', 'TABLO_TRUSTED_PROXIES' => '',
            'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_COOKIE_SECURE' => '0',
        ]);
    }

    private static function awaitNewCookie(TemporaryDirectory $directory): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($directory->path . '/new-cookie-ready') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
        Assert::true(is_file($directory->path . '/new-cookie-ready'), 'real concurrent login response reached shared cookie engine while old network is blocked');
    }
}
