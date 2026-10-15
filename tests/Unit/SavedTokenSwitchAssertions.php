<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteRepository;
use Tablo\GitTokenRepository;

use Testo\Assert;

final class SavedTokenSwitchAssertions
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitTokenRepository $tokens) {}

    public function manual(array $sample, array $ids): void
    {
            [$id, $first, $second] = $ids;
            $this->sites->save(array_replace($sample, ['git_token_id' => '', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'own-token')]), $first);
            Assert::true($this->sites->find($first)['git_token_id'] === null && $this->sites->tokenFor($this->sites->find($first)) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'own-token'), 'switch to manual token failed');
            $this->sites->save($sample + ['git_token_id' => ''], $second);
            $this->tokens->delete($id);
            Assert::true($this->tokens->find($id) === null && $this->sites->find($first) !== null && $this->sites->find($second) !== null, 'delete removed sites');

    }
}
