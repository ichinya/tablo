<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubConnection;
use Tablo\GitHubRequestPolicy;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\TraceInspector;
use Testo\Assert;

final class ResponsePersistedReplay extends GitHubPrivacyFixtureCase
{
    public function __construct(private readonly int $now, #[\SensitiveParameter] private readonly string $secret, #[\SensitiveParameter] private readonly string $marker, private readonly TestServer $server, private readonly array $rows) {}

    public function run(string $path, MeasuredGitHubHttp $http, #[\SensitiveParameter] array $input, string $case, int $status): void
    {
        $now=$this->now; $secret=$this->secret; $marker=$this->marker; $server=$this->server; $rows=$this->rows;
        $primary = $case === 'primary429';
        $count = dirname($path) . '/count';
                    // Fresh PDO/connection sees only persisted safe quota state.
                    $db = Database::connect($path);
                    $sites = new SiteRepository($db, new TokenVault($path . '.key'));
                    $policy = new GitHubRequestPolicy($sites->githubCooldowns(), clock: new GitHubClock(epoch: static fn (): int => $now));
                    $connection = new GitHubConnection($sites, $http, $policy);
                    $provider = $connection->provider(null, $input);
                    $error = self::failure(static fn () => $provider->getLatestRelease('fixture/' . $case));
                    self::assertSupportedProviderTrace(TraceInspector::inspect($error, [$secret, $marker]));
                    foreach ([bin2hex(random_bytes(24)), ''] as $allowed) {
                        $other = $connection->provider(null, ['github_token' => $allowed]);
                        switch (true) {
case ($primary):
 Assert::same($other->getLatestRelease('fixture/control'), 'v1');
    break;
default:

                            $blocked = self::failure(static fn () => $other->getLatestRelease('fixture/control'));
                            self::assertSupportedProviderTrace(TraceInspector::inspect($blocked, [$secret, $marker]));

    break;
}
                    }
                    Assert::same(self::requests($count), $primary ? [200, $status, 200, 200] : [200, $status]);
                    (new ResponseStorageAssertions($server))->private($rows, $path, [$secret, $marker]);
                    unset($blocked, $error, $other, $provider, $connection, $policy, $sites, $http, $db);

    }
}
