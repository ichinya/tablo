<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubConnection;
use Tablo\SiteInput;

/** @phpstan-import-type SiteData from SiteInput
 * @phpstan-import-type Record from \Tablo\SqlGitTokensEncryptedTokenRow as EncryptedToken
 * @phpstan-import-type Record from \Tablo\SqlSitesGithubTokenRow as GithubToken
 * @phpstan-import-type Record from \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow as Cooldown
 */
abstract class GitHubKeyFixtureCase
{
    /** @return array{github_token: string, repository: string, ...} */
    protected static function input(#[\SensitiveParameter] string $token): array
    {
        return [...SiteInput::defaults(), 'name' => 'Fixture', 'url' => 'https://example.com',
            'repository' => 'fixture/control', 'github_token' => $token];
    }

    /** @param 'provider'|'branches'|'validate' $entry */
    protected static function invoke(GitHubConnection $connection, string $entry, #[\SensitiveParameter] array $input): void
    {
        match ($entry) {
            'provider' => $connection->provider(null, $input)->getLatestRelease(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($input, 'repository'))),
            'branches' => $connection->branches($input),
            'validate' => $connection->validate($input),
        };
    }

    /** @return list{list<EncryptedToken>, list<GithubToken>, list<Cooldown>} */
    protected static function rows(\PDO $db): array
    {
        return [
            \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT encrypted_token FROM git_tokens ORDER BY id'))->fetchAll(), \Tablo\SqlGitTokensEncryptedTokenRow::checked(...)),
            \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT github_token FROM sites ORDER BY id'))->fetchAll(), \Tablo\SqlSitesGithubTokenRow::checked(...)),
            \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM github_cooldowns ORDER BY scope, resource'))->fetchAll(), \Tablo\SqlGithubCooldownsEligibleAtResourceScopeRow::checked(...)),
        ];
    }
}
