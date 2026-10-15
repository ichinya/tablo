<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubConnection;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;

final class GitHubSweepAssertions
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitHubConnection $connection) {}

    /** @param array{release: bool} $expectation */
    public function acceptedSites(array $expectation): void
    {
                        $release = $expectation['release'];
                        foreach ($this->sites->all() as $site) {
                            $state = (new SiteChecker(new FakeHttp([UnitFixtures::response()]), $this->connection->provider($site)))->check($site);
                            Assert::same($state['online'], 1);
                            Assert::same($state['last_error'], null);
                            Assert::same($state['latest_release'], $release ? 'v1' : null);
                            Assert::same($state['open_issues'], 2);
                            Assert::same($state['open_prs'], 2);
                            Assert::true($this->sites->storeCheck($site, $state));
                        }

    }
}
