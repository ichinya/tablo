<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenIsolationTest
{
    #[Test]
    public function endpointCredentialsNeverSelectGitIdentityOrEnterGitMemoAndPreview(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-git-');
        $db = $vault = $sites = $connection = $old = null;
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $base = UnitFixtures::site();
            $one = $sites->save([...$base, 'endpoint_token' => EndpointFixture::tokenA()]);
            $two = $sites->save([...$base, 'endpoint_token' => EndpointFixture::tokenB()]);
            $http = new FakeHttp([UnitFixtures::response(200, '{"tag_name":"v1"}'),
                UnitFixtures::response(200, '{"tag_name":"v2"}'),
                UnitFixtures::response(200, '{"default_branch":"main"}'),
                UnitFixtures::response(200, '[{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}]')]);
            $connection = new GitHubConnection($sites, $http);
            $old = $connection->provider($sites->find($one));
            Assert::same($old->getLatestRelease($base['repository']), 'v1');
            Assert::same($connection->provider($sites->find($two))->getLatestRelease($base['repository']), 'v1');
            Assert::same(count($http->requests), 1, 'different endpoint secrets share unchanged anonymous Git memo');
            $sites->save([...$base, 'endpoint_token' => EndpointFixture::tokenB()], $one);
            $caught = null;
            try { $old->getLatestRelease($base['repository']); } catch (GitHubFailure $error) { $caught = $error->reason; unset($error); }
            Assert::same($caught, 'credential-changed', 'accepted config-revision guard still rejects an old memo handle');
            Assert::same($connection->provider($sites->find($one))->getLatestRelease($base['repository']), 'v2');
            $preview = $connection->branches([...$base, 'endpoint_token' => EndpointFixture::tokenA()], $sites->find($two));
            Assert::same($preview['branches'], ['main']);
            foreach ($http->requests as [$url, $headers]) {
                Assert::true(str_starts_with($url, 'https://api.github.com/'));
                Assert::false((bool) array_filter($headers, static fn ($header) => str_starts_with($header, 'Authorization:')));
                Assert::false(str_contains(json_encode([$url, $headers]), EndpointFixture::tokenA()));
                Assert::false(str_contains(json_encode([$url, $headers]), EndpointFixture::tokenB()));
            }
        } finally { $old = $connection = $sites = $vault = $db = null; $directory->close(); }
    }
}
