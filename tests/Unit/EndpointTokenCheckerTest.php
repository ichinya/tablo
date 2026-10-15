<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteChecker;
use Tablo\HttpFailure;
use Tablo\VersionResponse;
use Tablo\SiteProjection;
use Tablo\SharedKeyFailure;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Tablo\Tests\Support\TraceInspector;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenCheckerTest
{
    #[Test]
    public function reflectedVersionAndCanonicalCommitFailBeforeEnteringPublicState(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-reflection-');
        $vault = new TokenVault($directory->path . '/key');
        $token = EndpointFixture::tokenA();
        $hex = str_repeat(chr(65), times: 40);
        $cases = [
            [$token, ['version' => $token], ''],
            [$token, ['version' => 'build-' . $token], ''],
            [$token, ['version' => $token . '-release'], ''],
            [$token, ['build' => ['version' => $token]], '$.build.version'],
            [$hex, ['commit' => $hex], ''],
            [$hex, ['commit' => strtolower($hex)], ''],
            [strtolower($hex), ['commit' => $hex], ''],
            [chr(49), ['version' => '1.0.0'], ''],
            [chr(65), ['commit' => 'abcdef1'], ''],
        ];
        try {
            foreach ($cases as [$credential, $document, $path]) {
                $http = new FakeHttp([UnitFixtures::response(200), UnitFixtures::response(200, json_encode($document, JSON_THROW_ON_ERROR))]);
                $state = (new SiteChecker($http, new FakeProvider(), $vault))->check([
                    ...UnitFixtures::site(), 'endpoint_token' => $vault->encrypt($credential), 'version_json_path' => $path]);
                Assert::same($state['online'], 1);
                Assert::same($state['latest_release'], 'v1.3.2');
                Assert::true($state['deployed_version'] === null, 'reflected version is rejected');
                Assert::true($state['deployed_commit'] === null, 'reflected commit is rejected');
                Assert::same($state['last_error'], 'Version: ' . HttpFailure::MESSAGES['invalid-response']);
            }
            $http = new FakeHttp([UnitFixtures::response(200), UnitFixtures::response(200, '{"version":"1.0.0","commit":"ABCDEF1"}')]);
            $state = (new SiteChecker($http, new FakeProvider(), $vault))->check([
                ...UnitFixtures::site(), 'endpoint_token' => $vault->encrypt($token)]);
            Assert::same($state['deployed_version'], '1.0.0');
            Assert::same($state['deployed_commit'], 'abcdef1');
            Assert::same($state['last_error'], null);
        } finally { $http = null; $vault = null; $directory->close(); }
    }

    #[Test]
    public function reflectionFailureHasProtectedRealArgumentsAndNoPreviousException(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 7);
            return;
        }
        $previous = (string) ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            $token = EndpointFixture::tokenA();
            $error = null;
            try { VersionResponse::decode(json_encode(['version' => $token], JSON_THROW_ON_ERROR), '', $token); }
            catch (HttpFailure $caught) { $error = $caught; }
            Assert::instanceOf($error, HttpFailure::class);
            Assert::same($error->reason, 'invalid-response');
            Assert::same($error->getPrevious(), null);
            $inspection = TraceInspector::inspect($error, [$token]);
            Assert::false($inspection['leaked']);
            Assert::true($inspection['redacted'] >= 3);
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $previous); }
        Assert::same(ini_get('zend.exception_ignore_args'), $previous);
    }

    #[Test]
    public function endpointErrorsRemainIndependentAndDisabledVersionMakesNoRequest(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-checker-');
        $vault = new TokenVault($directory->path . '/key');
        try {
            $site = [...UnitFixtures::site(), 'endpoint_token' => $vault->encrypt(EndpointFixture::tokenA())];
            foreach ([401, 403] as $status) {
                $http = new FakeHttp([UnitFixtures::response(200), UnitFixtures::response($status, EndpointFixture::tokenA())]);
                $state = (new SiteChecker($http, new FakeProvider(), $vault))->check($site);
                Assert::same($state['online'], 1);
                Assert::same($state['latest_release'], 'v1.3.2');
                Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::string($state['last_error']), 'Version: HTTP ' . $status));
                Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string($state['last_error']), EndpointFixture::tokenA()));
                $http = new FakeHttp([UnitFixtures::response($status, EndpointFixture::tokenA()), UnitFixtures::response(200, '{"version":"1.0.0"}')]);
                $state = (new SiteChecker($http, new FakeProvider(), $vault))->check($site);
                Assert::same($state['online'], 0);
                Assert::same($state['health_error_code'], 'http');
                Assert::same($state['health_http_status'], $status);
                Assert::same($state['deployed_version'], '1.0.0');
                Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string($state['last_error']), EndpointFixture::tokenA()));
            }
            $http = new FakeHttp([UnitFixtures::response(200)]);
            $state = (new SiteChecker($http, new FakeProvider(), $vault))->check([...$site, 'version_path' => '']);
            Assert::same(count($http->requests), 1);
            Assert::same($state['online'], 1);
            Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at($http->requests, 0)[1], 0)), 'Authorization: Bearer ' . EndpointFixture::tokenA()));
        } finally { $vault = null; $http = null; $directory->close(); }
    }

    #[Test]
    public function malformedCipherFailsOnlyEndpointDomainAndSharedKeyFailurePropagates(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-domain-');
        $vault = new TokenVault($directory->path . '/key');
        try {
            $cipher = $vault->encrypt(EndpointFixture::tokenA());
            foreach (['damaged', 'v1:AA==', $vault->encrypt(' ')] as $invalid) {
                $http = new FakeHttp([]);
                $state = (new SiteChecker($http, new FakeProvider(), $vault))->check([...UnitFixtures::site(), 'endpoint_token' => $invalid]);
                Assert::same($state['online'], null);
                Assert::same($state['health_error_code'], 'check-error');
                Assert::same($state['latest_release'], 'v1.3.2');
                Assert::same(count($http->requests), 0);
            }
            unlink($directory->path . '/key');
            $failed = false;
            try { (new SiteChecker(new FakeHttp([]), new FakeProvider(), $vault))->check([...UnitFixtures::site(), 'endpoint_token' => $cipher]); }
            catch (SharedKeyFailure) { $failed = true; }
            Assert::true($failed);
            Assert::false(is_file($directory->path . '/key'));
        } finally { $vault = null; $http = null; $directory->close(); }
    }

    #[Test]
    public function publicAndGitProjectionsRemoveCredentialsAndSnapshots(): void
    {
        $secret = EndpointFixture::tokenA();
        $site = [...UnitFixtures::site(), 'endpoint_token' => $secret, 'github_token' => $secret,
            'selected_token_snapshot' => $secret, 'remove_endpoint_token' => \Tablo\FormCheckbox::VALUE];
        $public = SiteProjection::publicSite($site);
        Assert::true(\Tablo\Tests\Support\FixtureValue::at($public, 'has_endpoint_token'));
        Assert::true(\Tablo\Tests\Support\FixtureValue::at($public, 'has_github_token'));
        Assert::true(\Tablo\Tests\Support\FixtureValue::at(SiteProjection::publicSite(['endpoint_token' => EndpointFixture::invalidCiphertext()]), 'has_endpoint_token'), 'presence is independent of ciphertext validity');
        Assert::false(str_contains(json_encode($public, JSON_THROW_ON_ERROR), $secret));
        $git = SiteProjection::withoutEndpointCredential($site);
        Assert::false(array_key_exists('endpoint_token', \Tablo\Tests\Support\FixtureValue::row($git)));
        Assert::false(array_key_exists('remove_endpoint_token', \Tablo\Tests\Support\FixtureValue::row($git)));
        Assert::true(($git['github_token'] ?? null) !== null && ($git['selected_token_snapshot'] ?? null) !== null);
    }
}
