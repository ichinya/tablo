<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HealthChecksTest
{
    #[Test]
    public function checksBaseUrlWithStrict200AndIgnoresInactiveJsonSettings(): void
    {
        $input = array_replace(UnitFixtures::site(), ['url' => 'https://example.com/app', 'health_path' => '  ',
            'health_check_mode' => 'json', 'health_json_path' => 'not a path', 'health_json_operator' => '>',
            'health_json_expected_value' => 'not a number', 'version_path' => '']);
        $site = SiteRepository::normalize($input);
        Assert::same($site['health_path'], '');
        $missing = $input;
        unset($missing['health_path']);
        Assert::same(SiteRepository::normalize($missing)['health_path'], '');
        foreach ([200, 201, 204, 301, 302, 404, 500, 503] as $status) {
            $http = new FakeHttp([UnitFixtures::response($status, '<html>private-fixture</html>')]);
            $state = (new SiteChecker($http, new FakeProvider()))->check($site);
            Assert::same($http->requests, [['https://example.com/app', []]], 'no /up, no lost base path, no version request');
            Assert::same($state['online'], $status === 200 ? 1 : 0);
            Assert::same($state['health_http_status'], $status);
            Assert::same($state['health_error_code'], $status === 200 ? null : 'http');
            Assert::same($state['open_issues'], 4);
            Assert::false(str_contains($state['last_error'] ?? '', 'private-fixture'));
        }
        foreach (['//evil.example', 'https://evil.example', '/bad#fragment', []] as $path) {
            UnitFixtures::rejects(fn () => SiteRepository::normalize(array_replace($input, ['health_path' => $path])), 'nonempty endpoint must remain local and valid');
        }
    }
}
