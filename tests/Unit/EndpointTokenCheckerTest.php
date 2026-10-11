<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\SharedKeyFailure;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenCheckerTest
{
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
                Assert::true(str_contains($state['last_error'], 'Version: HTTP ' . $status));
                Assert::false(str_contains($state['last_error'], EndpointFixture::tokenA()));
                $http = new FakeHttp([UnitFixtures::response($status, EndpointFixture::tokenA()), UnitFixtures::response(200, '{"version":"1.0.0"}')]);
                $state = (new SiteChecker($http, new FakeProvider(), $vault))->check($site);
                Assert::same($state['online'], 0);
                Assert::same($state['health_error_code'], 'http');
                Assert::same($state['health_http_status'], $status);
                Assert::same($state['deployed_version'], '1.0.0');
                Assert::false(str_contains($state['last_error'], EndpointFixture::tokenA()));
            }
            $http = new FakeHttp([UnitFixtures::response(200)]);
            $state = (new SiteChecker($http, new FakeProvider(), $vault))->check([...$site, 'version_path' => '']);
            Assert::same(count($http->requests), 1);
            Assert::same($state['online'], 1);
            Assert::true(hash_equals($http->requests[0][1][0], 'Authorization: Bearer ' . EndpointFixture::tokenA()));
        } finally { $http = $vault = null; $directory->close(); }
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
        } finally { $http = $vault = null; $directory->close(); }
    }

    #[Test]
    public function publicAndGitProjectionsRemoveCredentialsAndSnapshots(): void
    {
        $secret = EndpointFixture::tokenA();
        $site = [...UnitFixtures::site(), 'endpoint_token' => $secret, 'github_token' => $secret,
            'selected_token_snapshot' => $secret, 'remove_endpoint_token' => '1'];
        $public = SiteRepository::publicSite($site);
        Assert::true($public['has_endpoint_token']);
        Assert::true($public['has_github_token']);
        Assert::true(SiteRepository::publicSite(['endpoint_token' => '0'])['has_endpoint_token'], 'presence is independent of ciphertext validity');
        Assert::false(str_contains(json_encode($public, JSON_THROW_ON_ERROR), $secret));
        $git = SiteRepository::withoutEndpointCredential($site);
        Assert::false(array_key_exists('endpoint_token', $git));
        Assert::false(array_key_exists('remove_endpoint_token', $git));
        Assert::true(isset($git['github_token'], $git['selected_token_snapshot']));
    }
}
