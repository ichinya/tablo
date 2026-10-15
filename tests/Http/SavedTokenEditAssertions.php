<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;

final class SavedTokenEditAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    public function rotate(array $shared, array $credential, array $ids, string $csrf): void
    {
        [$projectId, $tokenId] = $ids;
        $db = $this->web->database();
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete')['status'] === 200
            && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 1, 'token GET delete only confirms');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 422
            && $db->query('SELECT COUNT(*) FROM git_tokens')->fetchColumn() === 1, 'used token cannot be deleted');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['name' => 'Renamed', 'token' => '']))['status'] === 303
            && hash_equals($db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn(), $credential['encrypted_token']) , 'blank shared token preserves secret');
        $invalidToken = $this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => \Tablo\Tests\Support\FixtureCredentials::invalidHeader(label: 'header-injection')]));
        Assert::true($invalidToken['status'] === 422 && !str_contains($invalidToken['body'], 'fixture-secret')
            && hash_equals($db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn(), $credential['encrypted_token']) , 'invalid token value neither reflects nor overwrites secret');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token')]))['status'] === 303
            && $db->query("SELECT checked_at FROM sites WHERE id = {$projectId}")->fetchColumn() === null, 'shared token rotation invalidates project state');

    }
}
