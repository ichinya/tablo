<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SettingsRepository;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class SessionIdleTimeoutTest
{
    #[Test]
    public function realCookiesExpireAtIdleEqualityAndReauthenticationRotatesCookieAndCsrf(): void
    {
        foreach ([119 => 200, 120 => 303, 121 => 303] as $elapsed => $status) {
            $fixture = new SessionFixture(); $web = $fixture->web;
            try {
                $csrf = $web->authenticate(); (new SettingsRepository($web->database()))->updateIdleTimeout('2');
                $oldCookie = $fixture->sessionFile();
                copy($web->directory->path . '/cookies', $web->directory->path . '/old-cookies');
                $fixture->clock(SessionFixture::START + $elapsed);
                Assert::same($web->request('/')['status'], $status);
                if ($status === 200) {
                    $timestamps = $fixture->timestamps();
                    Assert::same($timestamps['authenticated_at'], SessionFixture::START);
                    Assert::same($timestamps['last_activity_at'], SessionFixture::START + $elapsed);
                    continue;
                }
                Assert::true($fixture->sessionFile() !== $oldCookie);
                Assert::false(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($fixture->sessionFile())), 'authenticated_at'));
                $login = $web->request('/login'); $fresh = WebFixture::csrf($login);
                Assert::true($fresh !== $csrf);
                Assert::same($web->request('/login', ['_csrf' => $csrf, 'password' => FixtureCredentials::value('fixture-password')])['status'], 419);
                Assert::same($web->request('/login', ['_csrf' => $fresh, 'password' => FixtureCredentials::value('fixture-password')])['status'], 303);
                Assert::same($web->request('/')['status'], 200);
                Assert::same($fixture->timestamps()['authenticated_at'], SessionFixture::START + $elapsed);
                Assert::same($web->request('/', options: new \Tablo\Tests\Support\WebRequestOptions(jar: 'old-cookies'))['status'], 303);
            } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
            finally { $fixture->close(); }
        }
    }

    #[Test]
    public function humanNavigationAndDeliberate422OnlyExtendIdleWhileAbsoluteEqualityExpires(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $csrf = $web->authenticate(); (new SettingsRepository($web->database()))->updateIdleTimeout('720');
            $fixture->clock(SessionFixture::START + 100);
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '<script>hostile</script>'])['status'], 422);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 100);
            $escaped = $web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '<script>hostile</script>']);
            Assert::false(str_contains($escaped['body'], '<script>hostile</script>'));
            Assert::true(str_contains($escaped['body'], '&lt;script&gt;hostile&lt;/script&gt;'));
            Assert::same((new SettingsRepository($web->database()))->getIdleTimeout(), 720);
            $fixture->clock(SessionFixture::START + 43_199);
            Assert::same($web->request('/settings')['status'], 200);
            Assert::same($fixture->timestamps()['authenticated_at'], SessionFixture::START);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 43_199);
            $fixture->clock(SessionFixture::START + 43_200);
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '720'])['status'], 303);
            Assert::same($web->request('/sites/branches', ['_csrf' => $csrf, 'repository' => 'fixture/public'])['status'], 401);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function rejectedRequestsAndCallerFlagsCannotTurnHumanOrAutomaticRoutesIntoKeepalive(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $csrf = $web->authenticate(); (new SettingsRepository($web->database()))->updateIdleTimeout('2');
            $fixture->clock(SessionFixture::START + 50);
            foreach ([['/settings', ['_csrf' => 'wrong', 'check_interval_minutes' => '1'], 419],
                ['/settings', ['_csrf' => $csrf, 'check_interval_minutes' => str_repeat('9', times: 17_000)], 413],
                ['/missing', ['_csrf' => $csrf], 404], ['/sites/999/check', ['_csrf' => $csrf], 404],
                ['/sites/999/edit', ['_csrf' => $csrf], 404], ['/sites/branches', ['_csrf' => $csrf, 'site_id' => '999'], 404],
                ['/login', ['_csrf' => $csrf, 'password' => FixtureCredentials::value('wrong')], 422]] as [$path, $input, $status]) {
                Assert::same($web->request($path, $input)['status'], $status);
                Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
            }
            foreach (['/logout', '/missing', '/sites/999/edit', '/settings/tokens/999/delete'] as $path) {
                Assert::same($web->request($path)['status'], 404);
                Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
            }
            for ($attempt = 0; $attempt < 6; ++$attempt) {
                Assert::same($web->request('/login', ['_csrf' => $csrf, 'password' => FixtureCredentials::value('wrong')])['status'], 422);
                Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START, 'failed login and actual limiter refusal are non-activity');
            }
            Assert::same($web->request('/login')['status'], 303);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
            Assert::same($fixture->timestamps()['session_observed_at'], SessionFixture::START + 50);
            $fixture->clock(SessionFixture::START + 60);
            Assert::same($web->request('/?automatic=1', options: new \Tablo\Tests\Support\WebRequestOptions(headers: ['X-Requested-With: XMLHttpRequest', 'X-Tablo-Automatic: 1']))['status'], 200);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 60);
            $fixture->clock(SessionFixture::START + 179);
            Assert::same($web->request('/login')['status'], 303);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 60);
            $fixture->clock(SessionFixture::START + 180);
            Assert::same($web->request('/')['status'], 303);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}
