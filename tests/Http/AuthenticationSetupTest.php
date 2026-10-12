<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;

final class AuthenticationSetupTest extends DashboardFixtureCase
{



    #[Test]
    public function redirectsFreshAndEmptyDatabasesToSetup(): void
    {
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $databasePath = \Tablo\Tests\Support\FixtureValue::object($web->directory)->path . '/test.sqlite';
        Assert::true(!is_file($databasePath), 'new installation starts without a database file');
        $response = $web->request('/');
        Assert::true($response['status'] === 303 && str_contains($response['headers'], 'Location: /setup'), 'missing database redirects to setup');
        Assert::true(is_file($databasePath), 'initial request creates the database schema');
        foreach (['/', '/login', '/settings'] as $path) {
            $response = $web->request($path);
            Assert::true($response['status'] === 303 && str_contains($response['headers'], 'Location: /setup'), 'database without administrator still requires setup');
        }
        $setup = $web->request('/setup');
        Assert::true($setup['status'] === 200 && str_contains($setup['body'], 'class="auth-shell"'), 'empty database keeps initial setup accessible');
    }





    #[Test]
    public function hidesSetupAfterInitializationForEverySession(): void
    {
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $csrf = $web->authenticate();
        $passwordHash = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($web->database()->query('SELECT password_hash FROM users WHERE id = 1'))->fetchColumn());
        foreach ([true, false] as $cookie) {
            foreach ([null, [], ['_csrf' => $csrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-password'), 'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-password')]] as $data) {
                $response = $web->request(path: '/setup', data: $data, options: new \Tablo\Tests\Support\WebRequestOptions(cookies: ($cookie) ? \Tablo\Tests\Support\CookiePolicy::Retained : \Tablo\Tests\Support\CookiePolicy::Omitted));
                Assert::true($response['status'] === 404 && !str_contains($response['headers'], 'Location:')
                    && !str_contains($response['body'], 'Создать панель'), 'initialized setup is unavailable for authenticated and anonymous GET/POST requests');
            }
        }
        Assert::same(\Tablo\SqlStatement::checked($web->database()->query('SELECT password_hash FROM users WHERE id = 1'))->fetchColumn(), $passwordHash, 'closed setup cannot replace administrator password');
    }
}
