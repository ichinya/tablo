<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

final class GitHubBudgetAssertions
{
    public function __construct(private readonly WebFixture $web) {}

    public function manual(array $manual, string $log): void
    {
            Assert::same($manual['online'], 1);
            Assert::same($manual['latest_release'], 'v1.0.0');
            Assert::same($manual['latest_commit'], str_repeat('a', times: 40));
            Assert::same($manual['open_issues'], null);
            Assert::same($manual['open_prs'], null);
            Assert::false(str_contains($manual['last_error'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'rate-fixture-token')));
            Assert::same(count(file($log)), 3, 'release, branch, issue; PR deferred');
            $rows = $this->web->database()->query('SELECT scope,resource FROM github_cooldowns ORDER BY scope')->fetchAll();
            Assert::same(count($rows), 2);
            Assert::true(str_starts_with($rows[0]['scope'], 'credential:v1:'));
            Assert::same($rows[0]['resource'], 'search');
            Assert::same($rows[1], ['scope' => 'site:1', 'resource' => 'search']);

    }
}
