<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteRepository;
use Tablo\GitTokenRepository;

use Testo\Assert;

final class SavedTokenSwitchAssertions
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitTokenRepository $tokens) {}

    /** @param list{int, int, int} $ids */
    public function manual(array $sample, array $ids): void
    {
            [$id, $first, $second] = $ids;
            $this->sites->save(array_replace($sample, ['git_token_id' => '', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'own-token')]), $first);
            Assert::true(\Tablo\Tests\Support\FixtureValue::row($this->sites->find(\Tablo\Tests\Support\FixtureValue::integer($first)))['git_token_id'] === null && $this->sites->tokenFor($this->sites->find(\Tablo\Tests\Support\FixtureValue::integer($first))) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'own-token'), 'switch to manual token failed');
            $this->sites->save($sample + ['git_token_id' => ''], $second);
            $this->tokens->delete(\Tablo\Tests\Support\FixtureValue::integer($id));
            Assert::true($this->tokens->find(\Tablo\Tests\Support\FixtureValue::integer($id)) === null && $this->sites->find(\Tablo\Tests\Support\FixtureValue::integer($first)) !== null && $this->sites->find(\Tablo\Tests\Support\FixtureValue::integer($second)) !== null, 'delete removed sites');

    }
}
