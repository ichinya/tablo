<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\GitTokenRepository;
use Tablo\HttpClient;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class GitHubCredentialTest
{
    private static function changed(\Closure $call): void
    {
        $error = null;
        try { $call(); } catch (GitHubFailure $caught) { $error = $caught; }
        Assert::instanceOf($error, GitHubFailure::class);
        Assert::same($error->reason, 'credential-changed');
    }

    #[Test]
    public function privateQuotaIdentityPreservesCiphertextAndDefaultAndExternalKeys(): void
    {
        $directory = new TemporaryDirectory('tablo-github-private-');
        try {
            $db = Database::connect($directory->path . '/test.sqlite');
            foreach ([TokenVault::forDatabase($db), new TokenVault($directory->path . '/external.key')] as $vault) {
                $ciphertext = $vault->encrypt('low-entropy-fixture');
                $scope = $vault->credentialScope('low-entropy-fixture');
                Assert::same($vault->decrypt($ciphertext), 'low-entropy-fixture');
                Assert::true(str_starts_with($ciphertext, 'v1:'));
                Assert::true((bool) preg_match('/^credential:v1:[a-f0-9]{64}$/D', $scope));
                Assert::false(str_contains($scope, hash('sha256', 'low-entropy-fixture')));
                Assert::false(str_contains($scope, 'low-entropy-fixture'));
                Assert::same($vault->credentialScope('low-entropy-fixture'), $scope);
                Assert::false(hash_equals($scope, $vault->credentialScope('different-fixture')));
            }
            $defaultKey = file_get_contents($directory->path . '/github-token.key');
            $externalKey = file_get_contents($directory->path . '/external.key');
            Assert::same((new TokenVault($directory->path . '/external.key'))->credentialScope('low-entropy-fixture'), $scope);
            Assert::false(hash_equals(TokenVault::forDatabase($db)->credentialScope('low-entropy-fixture'), $scope));
            Assert::true(hash_equals($defaultKey, file_get_contents($directory->path . '/github-token.key')));
            Assert::true(hash_equals($externalKey, file_get_contents($directory->path . '/external.key')));
            file_put_contents($directory->path . '/external.key', 'corrupt-fixture');
            $failure = null;
            try { $vault->credentialScope('private-fixture'); } catch (\RuntimeException $caught) { $failure = $caught; }
            Assert::instanceOf($failure, \RuntimeException::class);
            Assert::same(file_get_contents($directory->path . '/external.key'), 'corrupt-fixture');
            Assert::false(str_contains($failure->getMessage() . json_encode($failure->getTrace()), 'private-fixture'));
        } finally {
            unset($db, $vault);
            $directory->close();
        }
    }

    #[Test]
    public function equivalentTokenMemoKeepsPerHandleRevisionAndRenameGuards(): void
    {
        $directory = new TemporaryDirectory('tablo-github-memo-revision-');
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $tokens = new GitTokenRepository($db, $vault);
            $secret = bin2hex(random_bytes(24));
            $one = $tokens->save(['name' => 'One', 'provider' => 'github', 'token' => $secret]);
            $two = $tokens->save(['name' => 'Two', 'provider' => 'github', 'token' => $secret]);
            $base = array_replace(UnitFixtures::site(), ['version_path' => '']);
            $first = $sites->save($base + ['git_token_id' => $one]);
            $second = $sites->save($base + ['git_token_id' => $two]);
            $http = new FakeHttp([UnitFixtures::response(200, '{"tag_name":"v1"}'),
                UnitFixtures::response(200, '{"tag_name":"v2"}'), UnitFixtures::response(200, '{"tag_name":"v3"}')]);
            $connection = new GitHubConnection($sites, $http);
            $old = $connection->provider($sites->find($first));
            Assert::same($old->getLatestRelease('example/project'), 'v1');
            Assert::same($connection->provider($sites->find($second))->getLatestRelease('example/project'), 'v2');
            $tokens->save(['name' => 'Renamed', 'provider' => 'github'], $one);
            Assert::same($connection->provider($sites->find($first))->getLatestRelease('example/project'), 'v1');
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => $secret], $one);
            self::changed(fn () => $old->getLatestRelease('example/project'));
            Assert::same($connection->provider($sites->find($first))->getLatestRelease('example/project'), 'v3');
            Assert::same($connection->provider($sites->find($second))->getLatestRelease('example/project'), 'v2');
            Assert::same(count($http->requests), 3);
        } finally {
            unset($old, $connection, $sites, $tokens, $vault, $db);
            $directory->close();
        }
    }

    #[Test]
    public function removalAndReselectionRejectMemoAndLoadingResults(): void
    {
        $directory = new TemporaryDirectory('tablo-github-selection-');
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            $tokens = new GitTokenRepository($db, $vault);
            $secret = bin2hex(random_bytes(24));
            $one = $tokens->save(['name' => 'One', 'provider' => 'github', 'token' => $secret]);
            $two = $tokens->save(['name' => 'Two', 'provider' => 'github', 'token' => $secret]);
            $base = array_replace(UnitFixtures::site(), ['version_path' => '']);
            $id = $sites->save($base + ['git_token_id' => $one]);
            $http = new FakeHttp([UnitFixtures::response(200, '{"tag_name":"private"}'), UnitFixtures::response(200, '{"tag_name":"public"}')]);
            $connection = new GitHubConnection($sites, $http);
            $old = $connection->provider($sites->find($id));
            Assert::same($old->getLatestRelease('example/project'), 'private');
            $sites->save($base + ['git_token_id' => $two], $id);
            self::changed(fn () => $old->getLatestRelease('example/project'));
            $snapshot = $sites->find($id);
            $loading = new class($sites, $base, $id) extends HttpClient {
                public function __construct(private SiteRepository $sites, private array $base, private int $id) {}
                public function get(string $url, array $headers = []): array
                {
                    $this->sites->save($this->base + ['git_token_id' => '', 'remove_github_token' => 1], $this->id);
                    return UnitFixtures::response(200, '{"tag_name":"obsolete-private"}');
                }
            };
            $provider = (new GitHubConnection($sites, $loading))->provider($snapshot);
            self::changed(fn () => $provider->getLatestRelease('example/project'));
            Assert::false($sites->storeCheck($snapshot, ['latest_release' => 'obsolete-private']));
            Assert::same($connection->provider($sites->find($id))->getLatestRelease('example/project'), 'public');
            Assert::same(count($http->requests), 2);
        } finally {
            unset($old, $provider, $loading, $connection, $sites, $tokens, $vault, $db);
            $directory->close();
        }
    }
}
