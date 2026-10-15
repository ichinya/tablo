<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

final class ProtectedRouteRevocationAssertions
{
    public function __construct(private readonly WebFixture $web, private readonly \PDO $db) {}

    public function all(string $csrf, #[\SensitiveParameter] array $tables): void
    {
            foreach ([true, false] as $cookie) {
                foreach ([null, [], ['_csrf' => $csrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), 'confirmation' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret')]] as $data) {
                    Assert::same($this->web->request(path: '/setup', data: $data, options: new \Tablo\Tests\Support\WebRequestOptions(cookies: ($cookie) ? \Tablo\Tests\Support\CookiePolicy::Retained : \Tablo\Tests\Support\CookiePolicy::Omitted))['status'], 404);
                }
            }
            foreach (['/', '/settings', '/sites/new', '/sites/1/edit', '/sites/1/delete', '/settings/tokens/new',
                '/settings/tokens/1/edit', '/settings/tokens/1/delete'] as $route) {
                $result = $this->web->request($route);
                Assert::same($result['status'], 303);
                Assert::true(str_contains($result['headers'], 'Location: /login'));
            }
            foreach (['/sites/new', '/sites/branches', '/sites/1/edit', '/sites/1/delete', '/sites/1/check',
                '/settings/tokens/new', '/settings/tokens/1/edit', '/settings/tokens/1/delete', '/logout'] as $route) {
                Assert::same($this->web->request($route, ['_csrf' => $csrf, 'name' => 'hostile mutation'])['status'], 303);
            }
            foreach (['sites', 'git_tokens'] as $table) { Assert::same($this->db->query('SELECT * FROM ' . $table)->fetchAll(), $tables[$table]); }

    }
}
