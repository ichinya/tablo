<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\Database;
use Tablo\HttpFailure;
use Tablo\Presenter;
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

    #[Test]
    public function usesOneFailurePolicyForHomeHttpAndJsonChecks(): void
    {
        foreach (['', '/up', '/json-health'] as $path) {
            $site = array_replace(UnitFixtures::site(), ['health_path' => $path, 'health_check_mode' => 'json',
                'health_json_path' => '$.result', 'health_json_expected_value' => 'ok']);
            foreach (['timeout', 'refused', 'dns', 'ssrf', 'tls', 'size', 'invalid-response', 'invalid-url', 'network'] as $reason) {
                $state = (new SiteChecker(new FakeHttp([new HttpFailure($reason),
                    UnitFixtures::response(200, '{"version":"1.2.3"}')]), new FakeProvider()))->check($site);
                Assert::same($state['online'], null, 'incomplete check stays Unknown: ' . $reason);
                Assert::same($state['health_error_code'], $reason);
                Assert::same($state['health_http_status'], null);
                Assert::same($state['response_time_ms'], null);
                Assert::same($state['deployed_version'], '1.2.3');
                Assert::same($state['open_issues'], 4);
                Assert::true(str_contains($state['last_error'], HttpFailure::MESSAGES[$reason]));
            }
        }
        $site = array_replace(UnitFixtures::site(), ['health_path' => '', 'version_path' => '']);
        $state = (new SiteChecker(new FakeHttp([new RuntimeException('https://secret-user:secret-token@example.com/private-body')]), new FakeProvider()))->check($site);
        Assert::same($state['health_error_code'], 'check-error');
        Assert::false(str_contains($state['last_error'], 'secret-'));
        Assert::same((new HttpFailure('secret-token'))->reason, 'check-error');
    }

    #[Test]
    public function preservesExplicitHttpAndJsonSemanticsAndIndependentFailures(): void
    {
        $site = array_replace(UnitFixtures::site(), ['health_path' => '/up', 'version_path' => '']);
        foreach ([200, 201, 204] as $status) {
            $state = (new SiteChecker(new FakeHttp([UnitFixtures::response($status)]), new FakeProvider()))->check($site);
            Assert::same($state['online'], 1, 'explicit endpoint still accepts 2xx');
            Assert::same($state['health_error_code'], null);
        }
        $json = array_replace($site, ['health_check_mode' => 'json', 'health_json_path' => '$.result', 'health_json_expected_value' => 'ok']);
        foreach ([['{"result":"ok"}', 1, null], ['{"result":"error"}', 0, 'json-condition'],
            ['private-body', null, 'invalid-response'], ['{}', null, 'invalid-response']] as [$body, $online, $reason]) {
            $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(200, $body)]), new FakeProvider()))->check($json);
            Assert::same($state['online'], $online);
            Assert::same($state['health_error_code'], $reason);
            Assert::same($state['health_http_status'], 200);
            Assert::false(str_contains($state['last_error'] ?? '', 'private-body'));
        }
        $provider = new FakeProvider();
        $provider->fail = true;
        $site['health_path'] = '';
        $site['version_path'] = '/version';
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(200, '<html>ok</html>'), new HttpFailure('tls')]), $provider))->check($site);
        Assert::same($state['online'], 1);
        Assert::same($state['health_error_code'], null);
        Assert::same($state['health_http_status'], 200);
        Assert::true(str_contains($state['last_error'], 'Version:') && str_contains($state['last_error'], 'rate limit'));
    }

    #[Test]
    public function persistsReasonsAndClearsThemWhenEndpointChanges(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $input = array_replace(UnitFixtures::site(), ['health_path' => '', 'version_path' => '']);
        $id = $sites->save($input);
        $before = $sites->find($id);
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(503)]), new FakeProvider()))->check($before);
        Assert::true($sites->storeCheck($before, $state));
        $stored = $sites->find($id);
        Assert::same($stored['health_error_code'], 'http');
        Assert::same($stored['health_http_status'], 503);
        Assert::same(Presenter::site($stored)['health_reason_label'], 'HTTP 503');
        $sites->save(array_replace($input, ['health_path' => '/up']), $id);
        Assert::same($sites->find($id)['health_error_code'], null);
        Assert::same($sites->find($id)['health_http_status'], null);
        Assert::same($sites->find($id)['checked_at'], null);
        Assert::false($sites->storeCheck($before, $state), 'old empty-endpoint result cannot overwrite new /up settings');
        $after = $sites->find($id);
        $state = (new SiteChecker(new FakeHttp([new HttpFailure('dns')]), new FakeProvider()))->check($after);
        Assert::true($sites->storeCheck($after, $state));
        Assert::same(Presenter::site($sites->find($id))['health_reason_label'], HttpFailure::MESSAGES['dns']);
    }
}
