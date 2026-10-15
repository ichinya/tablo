<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SettingsRepository;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class SessionTimestampAdmissionTest
{
    #[Test]
    public function legacyMalformedFutureInconsistentAndBackwardTimestampsFailBeforeAction(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $csrf = $web->authenticate();
            $cases = [];
            foreach (['authenticated_at', 'last_activity_at', 'session_observed_at'] as $field) {
                foreach ([null, '1000000', 1_000_000.0, [], false, -1, SessionFixture::START + 1] as $value) { $cases[] = [$field, $value, false]; }
                $cases[] = [$field, null, true];
            }
            $cases[] = ['last_activity_at', SessionFixture::START - 1, false];
            $cases[] = ['session_observed_at', SessionFixture::START - 1, false];
            foreach ($cases as [$field, $value, $missing]) {
                match ($missing) { true => $fixture->omit($field), default => $fixture->corrupt($field, $value) };
                Assert::same($web->request('/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => '1'])['status'], 303);
                Assert::same((new SettingsRepository($web->database()))->getIdleTimeout(), 30);
                $login = $web->request('/login');
                Assert::same($web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => FixtureCredentials::value('fixture-password')])['status'], 303);
                $csrf = WebFixture::csrf($web->request('/'));
            }
            $fixture->clock(SessionFixture::START + 10);
            Assert::same($web->request('/missing')['status'], 404);
            Assert::same($fixture->timestamps()['session_observed_at'], SessionFixture::START + 10);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
            $fixture->clock(SessionFixture::START + 9);
            Assert::same($web->request('/')['status'], 303);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

}
