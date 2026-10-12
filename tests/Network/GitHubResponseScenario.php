<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubConnection;
use Tablo\GitHubRequestPolicy;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\TraceInspector;
use Testo\Assert;

final class GitHubResponseScenario extends GitHubPrivacyFixtureCase
{
    public function __construct(private readonly TemporaryDirectory $directory, private readonly TestServer $server, private readonly int $now, #[\SensitiveParameter] private readonly string $secret, #[\SensitiveParameter] private readonly string $marker) {}

    public function run(string $kind, string $case, int $delay): void
    {
        $directory=$this->directory; $server=$this->server; $now=$this->now; $secret=$this->secret; $marker=$this->marker;
        $count=$directory->path . '/count';
                    file_put_contents($count, data: '');
                    $path = $directory->path . '/' . $kind . '-' . $case . '.sqlite';
                    $db = Database::connect($path);
                    $vault = new TokenVault($path . '.key');
                    $sites = new SiteRepository($db, $vault);
                    $tokens = new GitTokenRepository($db, $vault);
                    $input = $kind === 'manual' ? ['github_token' => $secret] : ['git_token_id' =>
                        $tokens->save(['name' => 'Saved', 'provider' => 'github', 'token' => $secret])];
                    $http = new MeasuredGitHubHttp($server->base);
                    $policy = new GitHubRequestPolicy($sites->githubCooldowns(), clock: new GitHubClock(epoch: static fn (): int => $now));
                    $connection = new GitHubConnection($sites, $http, $policy);
                    $provider = $connection->provider(null, $input);
                    Assert::same($provider->getLatestRelease('fixture/control'), 'v1');
                    Assert::same($provider->getLatestRelease('fixture/control'), 'v1');
                    Assert::same(self::requests($count), [200], 'successful revision memo avoids a second HTTP request');
                    $error = self::failure(static fn () => $provider->getLatestRelease('fixture/' . $case));
                    // Assert privacy first: the exact frozen negative must fail here, not at fixture setup.
                    self::assertSupportedProviderTrace(TraceInspector::inspect($error, [$secret, $marker]));
                    ResponseRateAssertions::typed($error, $now, $delay);
                    $rows = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT scope,resource,eligible_at FROM github_cooldowns ORDER BY scope'))->fetchAll(), \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow::checked(...));
                    $primary = $case === 'primary429';
                    ResponseCooldownAssertions::stored($rows, $case, $kind, $now, $delay);
                    $status = str_contains($case, '429') ? 429 : 403;
                    Assert::same(self::requests($count), [200, $status], 'one actual rate response and no retry');
                    $again = self::failure(static fn () => $provider->getLatestRelease('fixture/' . $case));
                    self::assertSupportedProviderTrace(TraceInspector::inspect($again, [$secret, $marker]));
                    Assert::same(self::requests($count), [200, $status]);
                    unset($frame, $again, $error, $provider, $connection, $policy, $tokens, $sites, $vault, $db);
                    (new ResponsePersistedReplay($now, $secret, $marker, $server, $rows))->run($path, $http, $input, $case, $status);

    }
}
