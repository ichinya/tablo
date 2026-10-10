<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HealthFailurePolicyTest
{
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
}
