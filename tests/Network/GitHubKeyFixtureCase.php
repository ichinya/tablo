<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubConnection;
use Tablo\SiteInput;

abstract class GitHubKeyFixtureCase
{
    protected static function input(#[\SensitiveParameter] string $token): array
    {
        return array_replace(SiteInput::defaults(), ['name' => 'Fixture', 'url' => 'https://example.com',
            'repository' => 'fixture/control', 'github_token' => $token]);
    }

    protected static function invoke(GitHubConnection $connection, string $entry, #[\SensitiveParameter] array $input): void
    {
        match ($entry) {
            'provider' => $connection->provider(null, $input)->getLatestRelease($input['repository']),
            'branches' => $connection->branches($input),
            'validate' => $connection->validate($input),
        };
    }

    protected static function rows(\PDO $db): array
    {
        return [
            $db->query('SELECT encrypted_token FROM git_tokens ORDER BY id')->fetchAll(),
            $db->query('SELECT github_token FROM sites ORDER BY id')->fetchAll(),
            $db->query('SELECT * FROM github_cooldowns ORDER BY scope, resource')->fetchAll(),
        ];
    }
}
