<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\EndpointTarget;
use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TraceInspector;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class EndpointRedirectPolicyTest
{
    #[Test]
    public function resolvesRfc3986ReferencesWithoutChangingEncodedSeparatorsOrQueryPresence(): void
    {
        $base = EndpointTarget::parse('HTTPS://Example.COM/a/b?old=1');
        foreach (['/up/' => '/up/', 'version' => '/a/version', '../up' => '/up', './version' => '/a/version',
            '?new=1' => '/a/b?new=1', '?' => '/a/b?', '//example.com:443/up' => '/up',
            '../../g' => '/g', '../../../g' => '/g', '/./g' => '/g', '/../g' => '/g',
            'g/./h' => '/a/g/h', 'g/../h' => '/a/h', 'g?y/../x' => '/a/g?y/../x',
            '%2e%2e/%2f' => '/a/%2e%2e/%2f', '/a//b' => '/a//b'] as $reference => $suffix) {
            Assert::same(EndpointTarget::redirect($base, $reference)['url'], 'https://example.com:443' . $suffix);
        }
        Assert::same(EndpointTarget::parse('http://example.com')['origin'], EndpointTarget::parse('HTTP://EXAMPLE.COM:80/')['origin']);
        Assert::same(EndpointTarget::parse('https://[2606:4700:0:0:0:0:0:1111]/')['origin'],
            EndpointTarget::parse('https://[2606:4700::1111]:443/')['origin']);
        Assert::false(EndpointTarget::parse('https://alias.example/')['origin'] === $base['origin']);
    }

    #[Test]
    public function rejectsAmbiguousAuthoritiesControlsFragmentsAndMalformedReferences(): void
    {
        $base = EndpointTarget::parse('https://example.com/a/b');
        foreach (['', '#fragment', '/up#fragment', " /up", "/up\r\nX: value", "/up\0", '/up\\next', '/bad%zz',
            'https://user@example.com/', 'http:relative', '//[::1', '//example.com:0/', '//example.com:65536/',
            '//example.com./', '//%65xample.com/', '//0177.0.0.1/', '//127.1/', '//0x7f000001/', '//2130706433/',
            '//[fe80::1%25eth0]/', '//éxample.com/', str_repeat('a', 8193)] as $reference) {
            $reason = null;
            try { EndpointTarget::redirect($base, $reference); } catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::same($reason, 'redirect-location-invalid');
        }
    }

    #[Test]
    public function conservativeAddressPolicyRejectsIanaSpecialMulticastMappedAndAlternateForms(): void
    {
        // Predicate evidence only; these addresses are never contacted.
        foreach (['0.1.2.3', '10.0.0.1', '100.64.0.0', '100.127.255.255', '127.0.0.1', '169.254.1.1',
            '172.16.0.1', '192.0.0.9', '192.0.2.1', '192.31.196.1', '192.52.193.1', '192.88.99.1',
            '192.168.1.1', '192.175.48.1', '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255', '::', '::1', '::ffff:8.8.8.8',
            '0:0:0:0:0:ffff:808:808', '64:ff9b::808:808', '100::1', '2001::1', '2001:1::1', '2001:db8::1',
            '2002::1', '2620:4f:8000::1', '3fff::1', '5f00::1', 'fc00::1', 'fe80::1', 'ff02::1',
            '127.1', '0177.0.0.1', '2130706433', '0x7f000001', 'fe80::1%eth0'] as $address) {
            Assert::false(EndpointTarget::isPublicAddress($address));
        }
        foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.0', '198.17.255.255',
            '198.20.0.0', '2606:4700::1111', '2001:4860:4860::8888'] as $address) {
            Assert::true(EndpointTarget::isPublicAddress($address));
        }
    }

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
        foreach ([['8.8.8.8', '10.0.0.1'], ['8.8.8.8', '::ffff:8.8.8.8'], ['8.8.8.8', '198.18.0.1'], ['not-an-ip']] as $answers) {
            $http = new class($answers) extends HttpClient {
                public function __construct(private array $answers) { parent::__construct(); }
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

    private static function protectedFailure(#[\SensitiveParameter] HttpClient $http, #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $marker): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try { $http->getEndpoint($url, ['Authorization: Bearer ' . $marker]); }
            catch (HttpFailure $error) {
                $scan = TraceInspector::inspect($error, [$marker]);
                Assert::false($scan['leaked']);
                Assert::same($error->reason, 'check-error');
                Assert::same($error->getPrevious(), null);
                return;
            }
            throw new \RuntimeException('Missing bounded failure');
        } finally { ini_set('zend.exception_ignore_args', $previous); }
    }
}
