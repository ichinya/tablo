<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\TokenVault;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubEquivalentScopeTest extends GitHubCorrectionFixtureCase
{


    #[Test]
    public function equivalentTokensSharePrimaryAcrossHandlesConnectionsAndCli(): void
    {
        $directory = new TemporaryDirectory('tablo-github-equivalent-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $vault = new TokenVault($directory->path . '/external.key');
            $sites = new SiteRepository($db, $vault);
            $tokens = new GitTokenRepository($db, $vault);
            $secret = bin2hex(random_bytes(24));
            $other = bin2hex(random_bytes(24));
            $rotated = bin2hex(random_bytes(24));
            $saved = $tokens->save(['name' => 'One', 'provider' => 'github', 'token' => $secret]);
            $duplicate = $tokens->save(['name' => 'Duplicate', 'provider' => 'github', 'token' => $secret]);
            $base = array_replace(SiteInput::defaults(), ['name' => 'Manual', 'url' => 'https://example.com', 'repository' => 'fixture/primary']);
            $ids = [$sites->save($base + ['github_token' => $secret]), $sites->save($base + ['github_token' => $secret]),
                $sites->save($base + ['git_token_id' => $saved]), $sites->save($base + ['git_token_id' => $duplicate])];
            $sites->save(array_replace($base, ['name' => 'Other', 'repository' => 'fixture/control', 'github_token' => $other]));
            $sites->save(array_replace($base, ['name' => 'Anonymous', 'repository' => 'fixture/control']));
            $http = new MeasuredGitHubHttp($server->base);
            foreach ($ids as $id) {
                // Fresh PDO and connection deliberately share only persisted eligibility and vault key.
                $freshDb = Database::connect($path);
                $freshSites = new SiteRepository($freshDb, new TokenVault($directory->path . '/external.key'));
                self::failure(static fn () => (new GitHubConnection($freshSites, $http))->provider($freshSites->find($id))
                    ->getLatestRelease('fixture/primary'), 'rate-limit');
                unset($freshSites, $freshDb);
            }
            Assert::same($http->core, 1, 'only the first proven-equivalent credential reaches upstream');
            self::failure(static fn () => (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $secret])
                ->getLatestRelease('fixture/primary'), 'rate-limit');
            Assert::same($http->core, 1, 'an unsaved preview also uses the proven-equivalent quota');
            (new EquivalentQuotaAssertions($db))->privateIdentity($secret);
            (new EquivalentCliAssertions($directory))->persisted($path, $server->base, $secret);
            // An equivalent handle deferred without a request must retain eligibility on rotation too.
            $tokens->save(['name' => 'Rotated', 'provider' => 'github', 'token' => $rotated], $duplicate);
            self::failure(static fn () => (new GitHubConnection($sites, $http))->provider($sites->find($ids[3]))
                ->getLatestRelease('fixture/control'), 'rate-limit');
            Assert::same($http->core, 1);
            Assert::false(str_contains(file_get_contents($path), $secret));
            Assert::false(str_contains($server->diagnostics(), $secret));
        } finally {
            unset($freshSites, $freshDb, $sites, $tokens, $vault, $http, $db);
            $server?->close();
            $directory->close();
        }
    }
}
