<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\BuildMetadata;
use Tablo\SelfUpdateRepository;
use Tablo\SelfUpdateResult;
use Tablo\Tests\Support\CookiePolicy;
use Tablo\Tests\Support\DashboardStateAssertions;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class SelfUpdateTest
{
    #[Test]
    public function authenticatedBannerAndPollingReadPersistedNoticeWithoutProviderRequestsOrMutation(): void
    {
        $build = new BuildMetadata('ichinya/tablo', 'main', str_repeat('a', times: 40));
        $web = new WebFixture(environment: ['TABLO_BUILD_REPOSITORY' => $build->repository, 'TABLO_BUILD_BRANCH' => $build->branch, 'TABLO_BUILD_SHA' => $build->sha]);
        try {
            $csrf = $web->authenticate(); $db = $web->database(); $repository = new SelfUpdateRepository($db);
            $claim = $repository->claim($build, time()) ?? throw new \RuntimeException('Claim required.');
            Assert::true($repository->complete($claim, $build, time(), new SelfUpdateResult('behind', str_repeat('b', times: 40), 4)));
            $before = A::snapshot($db);
            foreach (['/', '/settings', '/settings'] as $path) {
                $response = $web->request($path); Assert::same($response['status'], 200);
                Assert::true(str_contains($response['body'], 'В ветке main доступны новые коммиты. Обновите Tablo.'));
                Assert::true(str_contains($response['body'], 'новых коммитов: 4'));
                Assert::true(str_contains($response['body'], 'https://github.com/ichinya/tablo/compare/' . $build->sha . '...' . str_repeat('b', times: 40)));
            }
            $state = DashboardStateAssertions::success($web->request('/dashboard/state'), $csrf);
            Assert::true(str_contains(FixtureValue::string($state['html'] ?? null), 'В ветке main доступны новые коммиты. Обновите Tablo.'));
            Assert::same(A::snapshot($db), $before);
            Assert::false(is_file($web->directory->path . '/github-calls.log'));
            $anonymous = $web->request('/login', options: new WebRequestOptions(cookies: CookiePolicy::Omitted));
            Assert::false(str_contains($anonymous['body'], 'data-self-update'));
        } finally { unset($repository, $db); $web->close(); }
    }

    #[Test]
    public function selfUpdateSettingsRequireSessionAndCsrfAndCommitOnlyValidatedValues(): void
    {
        $web = new WebFixture();
        try {
            $csrf = $web->authenticate();
            Assert::same($web->request('/settings/self-update', ['self_update_interval_minutes' => '5'], new WebRequestOptions(cookies: CookiePolicy::Omitted))['status'], 303);
            Assert::same($web->request('/settings/self-update', ['self_update_interval_minutes' => '5'])['status'], 419);
            $repository = new SelfUpdateRepository($web->database());
            foreach (['0', '525601', '-1', '0.1', '<script>private</script>', ['array']] as $value) {
                $response = $web->request('/settings/self-update', ['_csrf' => $csrf, 'self_update_interval_minutes' => $value, 'self_update_enabled' => '1']);
                Assert::same($response['status'], 422); Assert::same($repository->get()['interval_minutes'], 360);
                Assert::false(str_contains($response['body'], '<script>private</script>'));
            }
            Assert::same($web->request('/settings/self-update', ['_csrf' => $csrf, 'self_update_interval_minutes' => '60'])['status'], 303);
            Assert::same($repository->get()['enabled'], 0); Assert::same($repository->get()['interval_minutes'], 60);
        } finally { unset($repository); $web->close(); }
    }
}
