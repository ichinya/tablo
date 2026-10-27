<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\JsonRecord;
use Tablo\SettingsRepository;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class DashboardStateSessionRaceTest
{
    #[Test]
    public function independentHttpActionAcquiresReleasedSessionAndFencesPreparedSavedState(): void
    {
        foreach (['navigation', 'logout', 'idle', 'login', 'decrease', 'rollback'] as $race) { $this->race($race); }
    }

    private function race(string $race): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        $barrier = new TemporaryDirectory('tablo-dashboard-state-race-');
        $server = null; $child = null;
        try {
            $csrf = $web->authenticate(); (new SettingsRepository($web->database()))->updateIdleTimeout('2');
            A::insert($web->database());
            $server = new TestServer($barrier, dirname(__DIR__) . '/fixtures/dashboard-state-race-router.php',
                dirname(__DIR__, levels: 2) . '/public', [
                    'TABLO_DB' => $web->directory->path . '/test.sqlite',
                    'TABLO_TEST_RUNTIME' => $web->directory->path . '/runtime',
                    'TABLO_DASHBOARD_STATE_BARRIER' => $barrier->path,
                    'TABLO_COOKIE_SECURE' => '0', 'TABLO_TRUSTED_PROXIES' => '',
                ]);
            $action = new SessionConcurrentAction($fixture, $web->server->base);
            $action->prepare($race);
            $child = new Subprocess([PHP_BINARY, dirname(__DIR__) . '/fixtures/dashboard-state-request.php',
                $server->base . '/dashboard/state', $web->directory->path . '/cookies'], $web->directory);
            $child->send('');
            $this->entered($barrier);
            $action->run($race, $csrf);
            Assert::false(is_file($barrier->path . '/release'), 'concurrent native action completed while saved SELECT remained blocked');
            file_put_contents($barrier->path . '/release', data: 'release');
            $result = $child->finish(); $child = null;
            Assert::same($result['exit_code'], 0);
            $data = JsonRecord::decode($result['stdout']);
            Assert::same($data['status'] ?? null, $race === 'navigation' ? 200 : 401);
            Assert::same($data['redirected'] ?? null, false);
            Assert::same($data['sessionCookieIssued'] ?? null, false, 'late poll cannot replace the current cookie');
            $body = JsonRecord::decode(FixtureValue::string($data['body'] ?? null));
            Assert::same(array_key_exists('html', $body), $race === 'navigation');
            Assert::same((int) \Tablo\SqlStatement::checked($web->database()->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 0);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); $barrier->retainFailure($error); throw $error; }
        finally {
            file_put_contents($barrier->path . '/release', data: 'release');
            $child?->close(); $server?->close(); $fixture->close(); $barrier->close();
        }
    }

    private function entered(TemporaryDirectory $barrier): void
    {
        $deadline = microtime(true) + 5;
        do {
            clearstatcache();
            if (is_file($barrier->path . '/entered')) { return; }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Actual saved-state SELECT did not reach its owned barrier.');
    }
}
