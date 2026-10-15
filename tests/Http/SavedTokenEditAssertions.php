<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;

/**
 * @phpstan-import-type Record from \Tablo\SqlGitTokensCreatedAtEncryptedTokenIdRow as Credential
 */
final class SavedTokenEditAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    /**
     * @param Credential $credential
     * @param array{string, string} $ids
     */
    public function rotate(array $shared, array $credential, array $ids, string $csrf): void
    {
        [$projectId, $tokenId] = $ids;
        $db = $this->web->database();
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete')['status'] === 200
            && \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM git_tokens'))->fetchColumn() === 1, 'token GET delete only confirms');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'] === 422
            && \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM git_tokens'))->fetchColumn() === 1, 'used token cannot be deleted');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['name' => 'Renamed', 'token' => '']))['status'] === 303
            && hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT encrypted_token FROM git_tokens'))->fetchColumn()), $credential['encrypted_token']) , 'blank shared token preserves secret');
        $invalidToken = $this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => \Tablo\Tests\Support\FixtureCredentials::invalidHeader(label: 'header-injection')]));
        Assert::true($invalidToken['status'] === 422 && !str_contains($invalidToken['body'], 'fixture-secret')
            && hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT encrypted_token FROM git_tokens'))->fetchColumn()), $credential['encrypted_token']) , 'invalid token value neither reflects nor overwrites secret');
        Assert::true($this->web->request('/settings/tokens/' . $tokenId . '/edit', array_replace($shared, ['token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token')]))['status'] === 303
            && \Tablo\SqlStatement::checked($db->query("SELECT checked_at FROM sites WHERE id = {$projectId}"))->fetchColumn() === null, 'shared token rotation invalidates project state');

    }
}
