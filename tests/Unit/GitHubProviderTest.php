<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubProvider;
use Tablo\GitHubConnection;
use Tablo\SiteRepository;
use Tablo\TokenVault;

use RuntimeException;
use LogicException;
use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;

final class GitHubProviderTest
{
    #[Test]
    public function separatesIssuesFromPrsAndProtectsBranchToken(): void
    {
        $http = new FakeHttp([UnitFixtures::response(200, '{"total_count":4,"incomplete_results":false}'),
            UnitFixtures::response(200, '{"total_count":1,"incomplete_results":false}'), UnitFixtures::response(200, '{"name":"feature/topic","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}')]);
        $provider = new GitHubProvider($http, 'fixture-token');
        Assert::true($provider->getOpenIssuesCount('owner/repo') === 4 && $provider->getOpenPullRequestsCount('owner/repo') === 1, 'counts');
        $provider->getLatestCommit('owner/repo', 'feature/topic');
        Assert::true(str_contains($http->requests[0][0], 'is%3Aissue') && str_contains($http->requests[1][0], 'is%3Apr'), 'wrong search');
        Assert::true(str_ends_with($http->requests[2][0], '/branches/feature%2Ftopic'), 'branch not encoded or resolved as a tag');
        foreach ($http->requests as [$url, $headers]) {
            Assert::true(str_starts_with($url, 'https://api.github.com/'), 'token sent to arbitrary host');
            Assert::true(in_array('Authorization: Bearer fixture-token', $headers, strict: true), 'token missing');
        }
    }

    #[Test]
    public function preservesMissingReleasesAndRejectsIncompleteSearch(): void
    {
        $provider = new GitHubProvider(new FakeHttp([UnitFixtures::response(404), UnitFixtures::response(200, '{}')]));
        Assert::true($provider->getLatestRelease('owner/repo') === null, 'no releases not represented');
        try {
            (new GitHubProvider(new FakeHttp([UnitFixtures::response(200, '{"total_count":1,"incomplete_results":true}')])))->getOpenIssuesCount('owner/repo');
            throw new LogicException('incomplete search accepted');
        } catch (RuntimeException $e) {
            Assert::true(!($e instanceof LogicException), 'incomplete search accepted');
        }
    }

    #[Test]
    public function paginatesAndAuthenticatesSlashAndNumericBranches(): void
    {
        $page = array_map(static fn ($i) => ['name' => 'branch-' . $i, 'commit' => ['sha' => str_repeat('a', times: 40)]], range(1, end: 100));
        $http = new FakeHttp([UnitFixtures::response(200, '{"default_branch":"develop"}'), UnitFixtures::response(200, json_encode($page)),
            UnitFixtures::response(200, '[{"name":"develop","commit":{"sha":"' . str_repeat('b', times: 40) . '"}}]'),
            UnitFixtures::response(200, '{"name":"feature/topic","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}')]);
        $provider = new GitHubProvider($http, 'fixture-token');
        $result = $provider->getBranches('owner/repo');
        Assert::true(count($result['branches']) === 101 && $result['default_branch'] === 'develop', 'pagination truncated');
        Assert::true(str_ends_with($http->requests[2][0], '?per_page=100&page=2'), 'second page missing');
        $provider->requireBranch('owner/repo', 'feature/topic');
        Assert::true(str_ends_with($http->requests[3][0], '/branches/feature%2Ftopic'), 'branch treated as commit/ref or slash unencoded');
        foreach ($http->requests as [$url, $headers]) {
            Assert::true(in_array('Authorization: Bearer fixture-token', $headers, strict: true) && !str_contains($url, 'fixture-token'), 'branch auth missing or token in URL');
        }
        $numeric = new GitHubProvider(new FakeHttp([UnitFixtures::response(200, '{"default_branch":"123"}'),
            UnitFixtures::response(200, '[{"name":"123","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}]')]));
        Assert::true($numeric->getBranches('owner/repo')['branches'] === ['123'], 'numeric branch changed JSON type');
    }

    #[Test]
    public function rejectsInvalidBranchResultsWithoutLeakingSecrets(): void
    {
        $full = array_map(static fn ($i) => ['name' => 'branch-' . $i, 'commit' => ['sha' => str_repeat('a', times: 40)]], range(1, end: 100));
        $cases = [
            [UnitFixtures::response(401, '{"message":"fixture-secret"}')],
            [UnitFixtures::response(403, '{"message":"fixture-secret"}')],
            [UnitFixtures::response(404, '{"message":"fixture-secret"}')],
            [UnitFixtures::response(200, '{"default_branch":"main"}'), UnitFixtures::response(200, '[]')],
            [UnitFixtures::response(200, '{"default_branch":"main"}'), UnitFixtures::response(200, '{"name":"main"}')],
            [UnitFixtures::response(200, '{"default_branch":"main"}'), UnitFixtures::response(200, '[{"name":"main","commit":{"sha":"bad"}}]')],
            [UnitFixtures::response(200, '{"default_branch":"main"}'), new RuntimeException('timeout')],
            [UnitFixtures::response(200, '{"default_branch":"main"}'), ...array_fill(0, count: 10, value: UnitFixtures::response(200, json_encode($full)))],
        ];
        foreach ($cases as $responses) {
            try {
                (new GitHubProvider(new FakeHttp($responses), 'fixture-secret'))->getBranches('owner/repo');
                throw new LogicException('bad branch response accepted');
            } catch (RuntimeException $e) { Assert::true(!($e instanceof LogicException) && !str_contains($e->getMessage(), 'fixture-secret'), 'bad branch response accepted or secret leaked'); }
        }
    }

    #[Test]
    public function validatesBranchWithStoredCredentials(): void
    {
        $sample = UnitFixtures::site();
        $temporary = new TemporaryDirectory('tablo-connection-');
        $directory = $temporary->path;
        try {
            $sites = new SiteRepository(Database::connect(':memory:'), new TokenVault($directory . '/key'));
            // Synthetic fixture credentials; never valid for a real service.
            // @mago-expect lint:no-literal-password
            $id = $sites->save($sample + ['github_token' => 'fixture-token']);
            $site = $sites->find($id);
            $http = new FakeHttp([UnitFixtures::response(200, '{"default_branch":"main"}'),
                UnitFixtures::response(200, '[{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}]'),
                UnitFixtures::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}'), UnitFixtures::response(404)]);
            $connection = new GitHubConnection($sites, $http);
            $connection->branches(['repository' => $sample['repository']], $site);
            $connection->validate($sample, $site);
            foreach ($http->requests as [$url, $headers]) { Assert::true(in_array('Authorization: Bearer fixture-token', $headers, strict: true), 'saved token not used'); }
            // Synthetic fixture credentials; never valid for a real service.
            // @mago-expect lint:no-literal-password
            UnitFixtures::rejects(static fn () => $connection->validate($sample + ['github_token' => 'replacement-token'], $site), 'missing branch accepted');
            // Assert exact fixture values/ciphertext; this is not an authentication decision.
            // @mago-expect lint:no-insecure-comparison
            Assert::true($sites->find($id)['github_token'] === $site['github_token'], 'preview mutated site');
            // Synthetic fixture credentials; never valid for a real service.
            // @mago-expect lint:no-literal-password
            UnitFixtures::rejects(static fn () => $connection->branches(['repository' => 'owner/repo', 'github_token' => "a\r\nb"], $site), 'header injection accepted');
            Assert::true(count($http->requests) === 4, 'invalid credentials reached network');
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }
}
