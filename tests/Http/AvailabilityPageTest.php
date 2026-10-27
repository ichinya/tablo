<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Auth;
use Tablo\AvailabilityRepository;
use Tablo\SettingsRepository;
use Tablo\SiteRepository;
use Tablo\Tests\Support\AvailabilityFixtures as F;
use Tablo\Tests\Support\AvailabilityPageAssertions as A;
use Tablo\Tests\Support\CookiePolicy;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class AvailabilityPageTest
{
    #[Test]
    public function cookieAuthorityAndSuccessfulHumanNavigationUseExistingPolicy(): void
    {
        $fixture = new SessionFixture(); $web = $fixture->web;
        try {
            $web->authenticate(); $db = $web->database();
            $id = (new SiteRepository($db))->save(IncidentFixtures::site());
            (new SettingsRepository($db))->updateIdleTimeout('2');
            $path = '/sites/' . $id . '/history';
            A::loginRedirect($web->request($path, options: new WebRequestOptions(cookies: CookiePolicy::Omitted)));
            $fixture->clock(SessionFixture::START + 40);
            Assert::same($web->request('/sites/999/history')['status'], 404);
            Assert::same($web->request($path . '?period[]=24h')['status'], 422);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
            $fixture->clock(SessionFixture::START + 50);
            Assert::same($web->request($path)['status'], 200);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 50);
            $fixture->clock(SessionFixture::START + 170);
            A::loginRedirect($web->request($path));
            $login = $web->request('/login');
            Assert::same($web->request('/login', ['_csrf' => WebFixture::csrf($login),
                'password' => FixtureCredentials::value('fixture-password')])['status'], 303);
            $auth = new Auth($db); $password = FixtureCredentials::value('availability-replacement');
            $auth->changePassword($password, $password, FixtureValue::string($auth->credentialFingerprint()));
            A::loginRedirect($web->request($path));
        } finally { unset($auth, $db); $fixture->close(); }
    }

    #[Test]
    public function rendersEscapedFiniteProjectionAndRetainsReadOnlyState(): void
    {
        $web = new WebFixture();
        try {
            $web->authenticate(); $db = $web->database(); $sites = new SiteRepository($db);
            $input = array_replace(IncidentFixtures::site(), ['name' => '<img src=x onerror=alert(1)>']);
            $id = $sites->save($input); $time = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
            Assert::true(IncidentFixtures::accept($sites, $id, 1, $time, ['response_time_ms' => 0,
                'last_error' => 'availability-private-error', 'deployed_version' => 'availability-private-version']));
            Assert::true(IncidentFixtures::accept($sites, $id, 0, $time, ['response_time_ms' => 20]));
            Assert::true(IncidentFixtures::accept($sites, $id, null, $time, ['response_time_ms' => 999]));
            $sites->save(array_replace($input, ['enabled' => false]), $id);
            $before = F::protectedRows($db);
            A::guard($db, array_keys($before));
            $response = $web->request('/sites/' . $id . '/history');
            A::finiteProjection($response);
            Assert::same(F::protectedRows($db), $before);
            Assert::false(is_file($web->directory->path . '/github-calls.log'));
            Assert::false(is_file($web->directory->path . '/github-token.key'));
        } finally { unset($sites, $before, $db); $web->close(); }
    }

    #[Test]
    public function periodAndMissingSiteFailuresAreExplicitAndGetOnly(): void
    {
        $web = new WebFixture();
        try {
            $csrf = $web->authenticate(); $sites = new SiteRepository($web->database());
            $id = $sites->save(IncidentFixtures::site()); $path = '/sites/' . $id . '/history';
            foreach (['', '?period=24h', '?period=7d', '?period=30d'] as $query) {
                $response = $web->request($path . $query);
                Assert::same($response['status'], 200);
                Assert::true(str_contains($response['body'], 'UTC'));
                Assert::true(str_contains($response['body'], 'name="period"'));
            }
            foreach (['period=', 'period=1d', 'period[]=24h', 'period=24H'] as $query) {
                Assert::same($web->request($path . '?' . $query)['status'], 422);
            }
            foreach (['0', '01', '9223372036854775808', '999'] as $missing) {
                Assert::same($web->request('/sites/' . $missing . '/history')['status'], 404);
            }
            Assert::same($web->request($path, ['_csrf' => $csrf])['status'], 404);
            $sites->delete($id);
            Assert::same($web->request($path)['status'], 404);
        } finally { unset($sites); $web->close(); }
    }

    #[Test]
    public function overflowAndInsufficientDataNeverRenderFabricatedPercentages(): void
    {
        $web = new WebFixture();
        try {
            $web->authenticate(); $db = $web->database(); $sites = new SiteRepository($db);
            $id = $sites->save(IncidentFixtures::site()); $path = '/sites/' . $id . '/history';
            $time = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
            A::noPercentage($web->request($path), 'none');
            Assert::true(IncidentFixtures::accept($sites, $id, null, $time, ['response_time_ms' => 999]));
            A::noPercentage($web->request($path), 'none');
            Assert::true(IncidentFixtures::accept($sites, $id, 1, $time, ['response_time_ms' => 0]));
            $one = $web->request($path); A::noPercentage($one, 'insufficient');
            Assert::true(str_contains($one['body'], '0,0'));
            F::bulk($db, $id, AvailabilityRepository::MAXIMUM_ROWS - 1, $time);
            $overflow = $web->request($path);
            Assert::same($overflow['status'], 200);
            Assert::true(str_contains($overflow['body'], 'data-availability-overflow'));
            Assert::true(str_contains($overflow['body'], '12 000'));
            Assert::false(str_contains($overflow['body'], 'data-availability-chart'));
            Assert::false(str_contains($overflow['body'], 'data-availability-table'));
            Assert::false(str_contains($overflow['body'], 'data-availability-percent'));
        } finally { unset($sites, $db); $web->close(); }
    }

}
