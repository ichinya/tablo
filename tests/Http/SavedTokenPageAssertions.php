<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use PDO;
use Testo\Assert;

final class SavedTokenPageAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    public function privateMetadata(array $credential, string $tokenId): void
    {
        Assert::true(str_starts_with($credential['encrypted_token'], 'v1:') && !hash_equals($credential['encrypted_token'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) , 'shared token encrypted at rest');
        $settings = $this->web->request('/settings');
        Assert::true(str_contains($settings['body'], 'Shared &lt;token&gt;') && !str_contains($settings['body'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'))
            && !str_contains($settings['body'], $credential['encrypted_token']), 'settings exposes escaped metadata only');
        $tokenEdit = $this->web->request('/settings/tokens/' . $tokenId . '/edit');
        Assert::true($tokenEdit['status'] === 200 && str_contains($tokenEdit['body'], 'name="token" value=""')
            && !str_contains($tokenEdit['body'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')), 'shared token edit never returns value');
        Assert::true(str_contains($this->web->request('/sites/new')['body'], 'Shared &lt;token&gt;'), 'project selector includes saved token');

    }
    public function admittedProject(array $poolSite, string $tokenId, array $credential, string $csrf): void
    {
        $projectId = (string) $poolSite['id'];
        $db = $this->web->database();
        Assert::true((string) $poolSite['git_token_id'] === $tokenId && $poolSite['github_token'] === null && $poolSite['version_path'] === '', 'project references saved token without copying secret');
        $projectEdit = $this->web->request('/sites/' . $projectId . '/edit');
        Assert::true(preg_match('/<option value="' . $tokenId . '" selected/', $projectEdit['body']) === 1
            && !str_contains($projectEdit['body'], $credential['encrypted_token']), 'edit preserves token selection without secret');
        Assert::true($this->web->request('/sites/' . $projectId . '/check', ['_csrf' => $csrf])['status'] === 303, 'manual check accepts project without version endpoint');
        $state = $db->query("SELECT * FROM sites WHERE id = {$projectId}")->fetch(PDO::FETCH_ASSOC);
        Assert::true($state['latest_commit'] === str_repeat('a', times: 40) && $state['deployed_version'] === null
            && !str_contains($state['last_error'] ?? '', 'Version:'), 'optional version check skipped while Git metrics still update');

    }
}
