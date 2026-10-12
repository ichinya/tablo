<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\WebFixture;

final class AuthenticationLifecycleTest extends DashboardFixtureCase
{




    #[Test]
    public function clearsStaleAuthenticationWhenDatabaseNeedsSetupAgain(): void
    {
        $csrf = \Tablo\Tests\Support\FixtureValue::object($this->web)->authenticate();
        \Tablo\Tests\Support\FixtureValue::object($this->web)->database()->exec('DELETE FROM users');
        $response = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/');
        Assert::true($response['status'] === 303 && str_contains($response['headers'], 'Location: /setup'), 'reset database redirects an old authenticated session to setup');
        $setup = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup');
        Assert::true($setup['status'] === 200 && str_contains($setup['body'], 'class="auth-shell"')
            && !str_contains($setup['body'], 'class="app-shell"') && !str_contains($setup['body'], 'Основная навигация'), 'setup uses its standalone layout after a database reset');
        Assert::true(WebFixture::csrf($setup) !== $csrf, 'database reset invalidates the previous session CSRF token');
        $data = ['_csrf' => $csrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-password'), 'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-password')];
        Assert::same(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup', $data)['status'], 419, 'stale setup form is refused');
        Assert::same(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup', array_replace($data, ['_csrf' => WebFixture::csrf($setup)]))['status'], 303, 'reset installation can create a new administrator');
        Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/')['body'], 'class="app-shell"'), 'new setup restores the authenticated dashboard layout');
    }





    #[Test]
    public function setsUpAdministratorAndRotatesProtectedSession(): void
    {
        $setup = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup');
        Assert::true($setup['status'] === 200 && str_contains($setup['body'], 'Создать панель'), 'Volt setup page renders');
        Assert::true(str_contains(strtolower($setup['headers']), 'httponly') && str_contains(strtolower($setup['headers']), 'samesite=strict'), 'session cookie protections');
        Assert::true(str_contains($setup['headers'], "frame-ancestors 'none'"), 'CSP present');
        $csrf = WebFixture::csrf($setup);
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup', ['password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')])['status'] === 419, 'setup rejects missing CSRF');
        $result = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup', ['_csrf' => $csrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), 'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')]);
        Assert::true($result['status'] === 303 && str_contains($result['headers'], 'Location: /'), 'setup creates admin and authenticates');
        $dashboard = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/');
        Assert::true($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Ваши сайты.'), 'dashboard renders');
        $csrf = WebFixture::csrf($dashboard);
        Assert::true($csrf !== WebFixture::csrf($setup), 'authentication rotates CSRF');
    }





    #[Test]
    public function logsOutAndRequiresLoginAndClosesPublicSetup(): void
    {
        $csrf = \Tablo\Tests\Support\FixtureValue::object($this->web)->authenticate();
        $lookup = ['_csrf' => $csrf, 'repository' => 'fixture/private', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')];
        $shared = ['_csrf' => $csrf, 'name' => 'Shared token', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')];
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/logout', ['_csrf' => $csrf])['status'] === 303, 'logout succeeds');
        $anonymous = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/');
        Assert::true($anonymous['status'] === 303 && str_contains($anonymous['headers'], 'Location: /login'), 'dashboard requires login');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/sites/branches', $lookup)['status'] === 303, 'branch loading requires login');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/settings')['status'] === 303 && \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/settings/tokens/new', $shared)['status'] === 303, 'token settings require login');
        $login = \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/login');
        Assert::true($login['status'] === 200 && str_contains($login['body'], 'Войти в Tablo'), 'login page renders');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'wrong')])['status'] === 422, 'wrong password refused');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')])['status'] === 303, 'existing admin can log in');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/setup')['status'] === 404, 'public setup closes after first admin');
        Assert::true(\Tablo\Tests\Support\FixtureValue::object($this->web)->request('/storage/tablo.sqlite')['status'] === 404 && \Tablo\Tests\Support\FixtureValue::object($this->web)->request('/vendor/autoload.php')['status'] === 404, 'storage and source inaccessible through web root');
    }
}
