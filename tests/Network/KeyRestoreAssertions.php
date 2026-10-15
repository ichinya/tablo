<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubConnection;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class KeyRestoreAssertions extends GitHubKeyFixtureCase
{
    public function __construct(private readonly TemporaryDirectory $directory, private readonly \PDO $db, private readonly MeasuredGitHubHttp $http) {}

    public function equivalent(#[\SensitiveParameter] string $secret, array $keyIdentity): void
    {
        [$key, $keyHash] = $keyIdentity;
            foreach ([$this->directory->path . '/moved-original.key', $key] as $restored) {
                if ($restored === $key) { rename($this->directory->path . '/moved-original.key', $key); }
                Assert::same(hash_file('sha256', $restored), $keyHash);
                $sites = new SiteRepository($this->db, new TokenVault($restored));
                $connection = new GitHubConnection($sites, $this->http);
                try { self::invoke($connection, 'branches', self::input($secret)); Assert::true(false); }
                catch (ValidationException $error) { Assert::true(str_contains($error->getMessage(), 'лимит API')); }
                Assert::same($this->http->core, 1, 'original identity retains persisted cooldown');
                unset($error, $connection, $sites);
            }

    }
}
