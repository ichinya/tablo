<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;

final class SavedTokenDetachAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    /**
     * @param array{string, string} $ids
     */
    public function detached(array $selectedLookup, #[\SensitiveParameter] array $sharedSite, array $ids, string $csrf): void
    {
        [$projectId, $tokenId] = $ids;
        $db = $this->web->database();
        Assert::true($this->web->request('/sites/branches', array_replace($selectedLookup, ['site_id' => $projectId]))['status'] === 200, 'rotated token used for branches');
        Assert::true($this->web->request('/sites/' . $projectId . '/edit', array_replace($sharedSite, ['git_token_id' => '', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]))['status'] === 303, 'project can switch to manually entered token');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => 'wrong'])['status'] === 419, 'token delete requires CSRF');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 303
            && \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM git_tokens'))->fetchColumn() === 0
            && \Tablo\SqlStatement::checked($db->query("SELECT COUNT(*) FROM sites WHERE id = {$projectId}"))->fetchColumn() === 1, 'unused shared token deletes without removing project');
    }
}
