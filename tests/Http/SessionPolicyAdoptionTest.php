<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SettingsRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class SessionPolicyAdoptionTest
{
    #[Test]
    public function twoIndependentCookiesAdoptCurrentCommittedPolicyWithoutRestartOrRevival(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web; $second = new WebRequestOptions(jar: 'second-cookies');
        try {
            $csrf = $web->authenticate();
            self::loginSecond($web, $second);
            Assert::true($fixture->sessionFile() !== $fixture->sessionFile('second-cookies'));
            $fixture->clock(SessionFixture::START + 100);
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '1'])['status'], 303);
            Assert::same($fixture->timestamps()['authenticated_at'], SessionFixture::START);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 100);
            Assert::same($web->request('/', options: $second)['status'], 303, 'decrease applies before second session activity');
            $fixture->clock(SessionFixture::START + 150);
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '3'])['status'], 303);
            Assert::same($web->request('/', options: $second)['status'], 303, 'increase cannot revive destroyed authority');
            $secondCsrf = self::loginSecond($web, $second);
            $fixture->clock(SessionFixture::START + 200);
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '2'])['status'], 303);
            Assert::same($web->request('/login', options: $second)['status'], 303);
            Assert::same($fixture->timestamps('second-cookies')['last_activity_at'], SessionFixture::START + 150);
            $fixture->clock(SessionFixture::START + 250);
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '720'])['status'], 303);
            Assert::same($fixture->timestamps('second-cookies')['last_activity_at'], SessionFixture::START + 150);
            $fixture->clock(SessionFixture::START + 350);
            Assert::same($web->request('/settings', options: $second)['status'], 200, 'unrevoked session uses increased current policy');
            Assert::same($fixture->timestamps('second-cookies')['authenticated_at'], SessionFixture::START + 150);
            Assert::same($fixture->timestamps()['authenticated_at'], SessionFixture::START);
            Assert::same($web->request('/settings', ['_csrf' => $secondCsrf, 'check_interval_minutes' => '17'], $second)['status'], 303);
            Assert::same((new SettingsRepository($web->database()))->get(), 17);
            Assert::same((new SettingsRepository($web->database()))->getIdleTimeout(), 720);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function missingSingletonReadsDefaultsWithoutWritesAndCorruptPolicyRefusesBeforeActions(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $csrf = $web->authenticate(); $web->database()->exec('DELETE FROM installation_settings');
            $schema = (int) SqlStatement::checked($web->database()->query('PRAGMA schema_version'))->fetchColumn();
            Assert::same($web->request('/settings')['status'], 200);
            Assert::same((int) SqlStatement::checked($web->database()->query('SELECT count(*) FROM installation_settings'))->fetchColumn(), 0);
            Assert::same(SqlStatement::checked($web->database()->query('PRAGMA schema_version'))->fetchColumn(), $schema);
            Assert::false(is_file($web->directory->path . '/github-token.key'));
            Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '0002'])['status'], 303);
            Assert::same((new SettingsRepository($web->database()))->get(), 10);
            $web->database()->exec('PRAGMA ignore_check_constraints=ON; UPDATE installation_settings SET session_idle_timeout_minutes=0');
            Assert::same($web->request('/settings', ['_csrf' => $csrf, 'check_interval_minutes' => '99'])['status'], 500);
            Assert::same((new SettingsRepository($web->database()))->get(), 10);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    private static function loginSecond(WebFixture $web, WebRequestOptions $options): string
    {
        $login = $web->request('/login', options: $options);
        Assert::same($web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => FixtureCredentials::value('fixture-password')], $options)['status'], 303);
        return WebFixture::csrf($web->request('/settings', options: $options));
    }
}
