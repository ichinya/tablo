<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

abstract class LoginProxyFixtureCase
{

    protected function attempt(WebFixture $web, string $source, #[\SensitiveParameter] string $password,
        array $headers = [], ?string $base = null): array
    {
        $jar = 'jar-' . bin2hex(random_bytes(4));
        $login = $web->request(path: '/login', options: new \Tablo\Tests\Support\WebRequestOptions(headers: $headers, source: $source, base: $base, jar: $jar));
        Assert::same($login['status'], 200);
        $response = $web->request(path: '/login', data: ['_csrf' => WebFixture::csrf($login), 'password' => $password], options: new \Tablo\Tests\Support\WebRequestOptions(headers: $headers, source: $source, base: $base, jar: $jar));
        Assert::true(in_array($response['status'], [422, 303], strict: true));
        $access = $web->request(path: '/', options: new \Tablo\Tests\Support\WebRequestOptions(source: $source, base: $base, jar: $jar));
        Assert::same($access['status'], $response['status'] === 303 ? 200 : 303);
        if ($response['status'] !== 303) {
            Assert::true(str_contains($access['headers'], 'Location: /login'));
        }
        return $response;
    }

    protected function rows(WebFixture $web): array
    {
        $web->reopenDatabase();
        return $web->database()->query('SELECT key, failures FROM login_limits ORDER BY key')->fetchAll();
    }

    protected function row(string $address, int $failures): array
    {
        return ['key' => hash('sha256', $address), 'failures' => $failures];
    }
}
