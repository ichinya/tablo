<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitTokenRepository;
use Tablo\HttpClient;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class GitHubMemoRotationTest extends GitHubBudgetFixtureCase
{


    #[Test]
    public function sameIdRotationInvalidatesMemoAndRejectsOldProvidersAndInflightResults(): void
    {
        $directory = new TemporaryDirectory('tablo-github-rotation-');
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $tokens = new GitTokenRepository($db, $vault);
            $sites = new SiteRepository($db, $vault);
            $id = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')]);
            $siteId = $sites->save(array_replace(UnitFixtures::site(), ['version_path' => '', 'git_token_id' => $id]));
            $http = new FakeHttp([self::response(200, '{"tag_name":"v1"}'), self::response(200, '{"tag_name":"v2"}'),
                self::response(200, '{"tag_name":"v3"}')]);
            $connection = new GitHubConnection($sites, $http);
            $old = $connection->provider($sites->find($siteId));
            Assert::same($old->getLatestRelease('example/project'), 'v1');
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => ''], $id);
            Assert::same($connection->provider($sites->find($siteId))->getLatestRelease('example/project'), 'v1');
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')], $id);
            self::failure(static fn () => $old->getLatestRelease('example/project'), 'credential-changed');
            Assert::same($connection->provider($sites->find($siteId))->getLatestRelease('example/project'), 'v2');
            $snapshot = $sites->find($siteId);
            $rotating = new class($tokens, $id) extends HttpClient {
                public function __construct(private GitTokenRepository $tokens, private int $id) {}
                public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
                {
                    $this->tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'rotated-secret')], $this->id);
                    return UnitFixtures::response(200, '{"tag_name":"obsolete"}');
                }
            };
            $provider = (new GitHubConnection($sites, $rotating))->provider($snapshot);
            self::failure(static fn () => $provider->getLatestRelease('example/project'), 'credential-changed');
            Assert::false($sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot), array_fill_keys(['online','health_error_code','health_http_status',
                'deployed_version','deployed_commit','latest_release','latest_commit','open_issues','open_prs','response_time_ms','last_error','checked_at'], value: null)));
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($siteId))['checked_at'], null);
        } finally {
            unset($provider, $rotating, $old, $connection, $sites, $tokens, $vault, $db);
            $directory->close();
        }
    }
}
