<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteRepository;
use Tablo\GitTokenRepository;

use Testo\Assert;

final class SavedTokenReferenceAssertions
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitTokenRepository $tokens) {}

    public function selected(array $sample, array $ids): array
    {
        [$first, $id] = $ids;
            $site = $this->sites->find($first);
            Assert::true($site['github_token'] === null && $site['git_token_id'] === $id, 'saved token copied instead of referenced');
            Assert::true( hash_equals($this->sites->resolveToken([], $site), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) && hash_equals($this->sites->resolveToken(['git_token_id' => (string) $id], null), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) , 'saved token not resolved');
            Assert::true($this->sites->resolveToken(['git_token_id' => ''], $site) === '', 'deselected pool token was reused');
            Assert::true($this->tokens->all()[0]['site_count'] === 2, 'usage count wrong');
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->sites->resolveToken(['git_token_id' => (string) $id, 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'own-token')], null))))->verify('ambiguous token accepted');
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->sites->save($sample + ['git_token_id' => '999']))))->verify('missing pool token accepted');
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->sites->save($sample + ['git_token_id' => ['array']]))))->verify('malformed pool id accepted');
        return $site;
    }
}
