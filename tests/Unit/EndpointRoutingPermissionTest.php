<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class EndpointRoutingPermissionTest extends EndpointPolicyFixtureCase
{


    #[Test]
    public function endpointPermissionIsExplicitOptionalAndNeverAppliedToHomepage(): void
    {
        foreach ([['', '', 0], ['', '/version', 1], ['/up', '', 1], ['/up', '/version', 2]] as [$health, $version, $count]) {
            $http = new FakeHttp([UnitFixtures::response(200, '{}'), UnitFixtures::response(200, '{"version":"v1"}')]);
            (new SiteChecker($http, new FakeProvider()))->check([...UnitFixtures::site(), 'health_path' => $health, 'version_path' => $version]);
            Assert::same(count($http->endpointRequests), $count);
            foreach ($http->endpointRequests as $permission) { Assert::same($permission, [3, null]); }
            Assert::same(count($http->requests), $version === '' ? 1 : 2);
        }
        foreach (['redirect-location-missing', 'redirect-location-invalid', 'redirect-origin', 'redirect-cycle', 'redirect-limit'] as $reason) {
            $http = new FakeHttp([new HttpFailure($reason), UnitFixtures::response(200, '{"version":"v1"}')]);
            $state = (new SiteChecker($http, new FakeProvider()))->check(UnitFixtures::site());
            Assert::same($state['online'], null);
            Assert::same($state['health_http_status'], null);
            Assert::same($state['health_error_code'], $reason);
            Assert::same($state['deployed_version'], 'v1');
            Assert::same($state['open_issues'], 4);
        }
    }



    #[Test]
    public function initialAllAnswerResolverFailuresAndSensitiveArgumentsFailClosed(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 7);
            return;
        }
        foreach ([['8.8.8.8', '10.0.0.1'], ['8.8.8.8', '::ffff:8.8.8.8'], ['8.8.8.8', '198.18.0.1'], ['not-an-ip']] as $answers) {
            $http = new class($answers) extends HttpClient {
                /** @param list<string> $answers */
                public function __construct(private array $answers) { parent::__construct(); }
                /** @return list<string> */
                protected function resolve(#[\SensitiveParameter] string $host): array { return $this->answers; }
            };
            $reason = null;
            try { $http->getEndpoint('https://synthetic.example/up'); } catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::same($reason, 'ssrf', 'resolver double rejects every answer before native transfer');
        }
        $marker = 'synthetic-redirect-private-marker';
        $http = new class extends HttpClient {
            protected function resolve(#[\SensitiveParameter] string $host): array { throw new \RuntimeException('fixed'); }
        };
        self::protectedFailure($http, 'https://' . $marker . '.example/path', $marker);
    }
}
