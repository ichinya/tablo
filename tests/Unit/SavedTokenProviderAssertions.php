<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\GitHubConnection;
use Tablo\SiteRepository;

use Testo\Assert;
use Tablo\Tests\Support\FakeHttp;

final class SavedTokenProviderAssertions
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites) {}

    public function refuses(string $cipher): void
    {
            \Tablo\SqlStatement::checked($this->db->prepare('INSERT INTO git_tokens (name, provider, encrypted_token) VALUES (?, ?, ?)'))->execute(['Future provider', 'gitlab', $cipher]);
            $foreign = (string) $this->db->lastInsertId();
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => $this->sites->resolveToken(['git_token_id' => $foreign], null))))->verify('token from another provider accepted');
            $unsupportedHttp = new FakeHttp([]);
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(fn () => (new GitHubConnection($this->sites, $unsupportedHttp))->provider(['provider' => 'gitlab', 'git_token_id' => (int) $foreign]))))->verify('unsupported provider token could reach GitHub');
            Assert::true($unsupportedHttp->requests === [], 'unsupported provider reached network');

    }
}
