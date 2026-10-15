<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class ExplicitHealthChecksTest
{
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
}
