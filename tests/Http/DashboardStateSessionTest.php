<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\JsonRecord;
use Tablo\SettingsRepository;
use Tablo\Tests\Support\CookiePolicy;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class DashboardStateSessionTest
{
    #[Test]
    public function realAutomaticRouteOnlyMovesObservationAndExpiresAtIdleEquality(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $web->authenticate(); (new SettingsRepository($web->database()))->updateIdleTimeout('2');
            foreach ([10, 50, 119] as $elapsed) {
                $fixture->clock(SessionFixture::START + $elapsed);
                Assert::same($web->request('/dashboard/state?human=1', options: new WebRequestOptions(headers: ['X-Requested-With: XMLHttpRequest']))['status'], 200);
                Assert::same($fixture->timestamps(), ['authenticated_at' => SessionFixture::START,
                    'last_activity_at' => SessionFixture::START, 'session_observed_at' => SessionFixture::START + $elapsed]);
            }
            $fixture->clock(SessionFixture::START + 120);
            self::refused($web->request('/dashboard/state'));
            (new SettingsRepository($web->database()))->updateIdleTimeout('720');
            self::refused($web->request('/dashboard/state'));
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function anonymousLogoutAndAbsoluteDeadlineReturnJsonWithoutRedirects(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $csrf = $web->authenticate();
            self::refused($web->request('/dashboard/state', options: new WebRequestOptions(cookies: CookiePolicy::Omitted)));
            Assert::same($web->request('/logout', ['_csrf' => $csrf])['status'], 303);
            self::refused($web->request('/dashboard/state'));
            $login = $web->request('/login');
            Assert::same($web->request('/login', ['_csrf' => \Tablo\Tests\Support\WebFixture::csrf($login),
                'password' => FixtureCredentials::value('fixture-password')])['status'], 303);
            (new SettingsRepository($web->database()))->updateIdleTimeout('720');
            $fixture->clock(SessionFixture::START + 43_199);
            Assert::same($web->request('/dashboard/state')['status'], 200);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
            $fixture->clock(SessionFixture::START + 43_200);
            self::refused($web->request('/dashboard/state'));
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function committedPasswordChangeRevokesThePollingCookie(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        $auth = null;
        try {
            $web->authenticate();
            $auth = new \Tablo\Auth($web->database());
            $replacement = FixtureCredentials::value('dashboard-state-password-replacement');
            $auth->changePassword($replacement, $replacement,
                \Tablo\Tests\Support\FixtureValue::string($auth->credentialFingerprint()));
            self::refused($web->request('/dashboard/state'));
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $auth = null; $fixture->close(); }
    }

    /** @param array{status: int, headers: string, body: string} $response */
    private static function refused(array $response): void
    {
        Assert::same($response['status'], 401);
        Assert::same(JsonRecord::decode($response['body']), ['error' => 'Authentication required.']);
        Assert::false(str_contains($response['headers'], 'Location:'));
        Assert::true(str_contains(strtolower($response['headers']), 'content-type: application/json'));
    }
}
