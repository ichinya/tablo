<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;

final class SavedTokenCreateAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    public function admitted(array $shared): void
    {
        Assert::true($this->web->request('/settings/tokens/new', ['name' => 'Without CSRF', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')])['status'] === 419, 'settings token create requires CSRF');
        Assert::true($this->web->request('/settings/tokens/new', array_replace($shared, ['token' => '']))['status'] === 422, 'new shared token requires a value');
        Assert::true($this->web->request('/settings/tokens/new', array_replace($shared, ['provider' => 'gitlab']))['status'] === 422, 'unsupported Git provider is rejected');
        Assert::true($this->web->request('/settings/tokens/new', $shared)['status'] === 303, 'shared token create succeeds');

    }
}
