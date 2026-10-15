<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubRequestPolicy;
use Tablo\SiteRepository;
use Testo\Assert;

final class SearchServiceAssertions
{
    public function __construct(private readonly GitHubRequestPolicy $policy, private readonly SiteRepository $sites) {}

    public function oneWindow(array $calls): void
    {
                Assert::same(count(array_filter($calls, static fn (string $path): bool => $path === '/up')), 21);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => $path === '/version')), 21);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => str_starts_with($path, '/repos/'))), 42);
                Assert::same(count(array_filter($calls, static fn (string $path): bool => str_starts_with($path, '/search/'))), 1);
                Assert::same($this->policy->admissions(), 43);
                foreach ($this->sites->all() as $site) {
                    Assert::same($site['online'], 1);
                    Assert::same($site['latest_release'], 'v1');
                    Assert::same($site['latest_commit'], str_repeat('a', times: 40));
                }
    }
}
