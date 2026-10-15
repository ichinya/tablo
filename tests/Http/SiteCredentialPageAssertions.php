<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;

final class SiteCredentialPageAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    public function privateLookup(string $csrf, array $row, int|string $id): void
    {
        Assert::true( !hash_equals($row['github_token'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) && str_starts_with($row['github_token'], 'v1:'), 'database stores only encrypted token');
        $edit = $this->web->request('/sites/' . $id . '/edit');
        Assert::true(!str_contains($edit['body'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) && !str_contains($edit['body'], $row['github_token'])
            && str_contains($edit['body'], 'Токен сохранён.'), 'edit shows saved status without credentials');
        $existingLookup = ['_csrf' => $csrf, 'site_id' => (string) $id, 'repository' => 'fixture/private'];
        Assert::true($this->web->request('/sites/branches', $existingLookup)['status'] === 200, 'branch lookup reuses saved token');
        Assert::true($this->web->request('/sites/branches', array_replace($existingLookup, ['remove_github_token' => \Tablo\FormCheckbox::VALUE]))['status'] === 422,
            'removal preview stops using saved token');

    }
}
