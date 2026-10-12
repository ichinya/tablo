<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubResponseAdmissionTest extends GitHubCorrectionFixtureCase
{


    #[Test]
    public function realRedirectsBranchMismatchAndListSizeKeepSafeGuidance(): void
    {
        $directory = new TemporaryDirectory('tablo-github-guidance-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $db = Database::connect(':memory:');
            $sites = new SiteRepository($db, new TokenVault($directory->path . '/key'));
            foreach ([301, 302] as $status) {
                $http = new MeasuredGitHubHttp($server->base);
                $connection = new GitHubConnection($sites, $http);
                $error = self::failure(static fn () => $connection->provider(null)->getLatestRelease('fixture/redirect-' . $status), 'renamed');
                Assert::same($error->httpStatus, $status);
                Assert::true(str_contains($error->getMessage(), 'Укажите актуальный адрес и ветку'));
                Assert::same($http->core, 1, 'redirect is diagnosed without following Location');
            }
            $http = new MeasuredGitHubHttp($server->base);
            $connection = new GitHubConnection($sites, $http);
            $secret = bin2hex(random_bytes(24));
            $provider = $connection->provider(null, ['github_token' => $secret]);
            $error = self::failure(static fn () => $provider->getLatestCommit('fixture/wrong-branch', 'main'), 'branch-unconfirmed');
            Assert::true(str_contains($error->getMessage(), 'Обновите список веток'));
            self::failure(static fn () => $provider->getLatestCommit('fixture/invalid-branch', 'main'), 'invalid-data');
            self::failure(static fn () => $provider->getLatestRelease('fixture/malformed'), 'invalid-data');
            self::failure(static fn () => $provider->getLatestRelease('fixture/transient'), 'unavailable');
            $largeHttp = new MeasuredGitHubHttp($server->base);
            $large = new GitHubConnection($sites, $largeHttp);
            try { $large->branches(['repository' => 'fixture/large-branches', 'github_token' => $secret]); Assert::true(false); }
            catch (ValidationException $error) {
                Assert::true(str_contains($error->getMessage(), 'Укажите нужную ветку вручную'));
                Assert::false(str_contains($error->getMessage(), 'fixture-secret'));
            }
            Assert::same($largeHttp->core, 11);
            try { $connection->validate(array_replace(SiteInput::defaults(), ['name' => 'Test',
                'url' => 'https://example.com', 'repository' => 'fixture/wrong-branch', 'github_token' => $secret])); Assert::true(false); }
            catch (ValidationException $error) {
                Assert::true(str_contains($error->getMessage(), 'Обновите список веток'));
                Assert::false(str_contains($error->getMessage(), 'fixture-secret'));
            }
        } finally {
            unset($error, $provider, $large, $connection, $sites, $http, $largeHttp, $db);
            $server?->close();
            $directory->close();
        }
    }
}
