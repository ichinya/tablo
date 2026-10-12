<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitTokenRepository;
use Tablo\SiteRepository;

final class CiphertextKeyFixture extends GitHubKeyFixtureCase
{
    public static function seed(string $kind, GitTokenRepository $tokens, SiteRepository $sites, #[\SensitiveParameter] string $secret): void
    {
                switch (true) {
case ($kind === 'saved'):
 $tokens->save(['name' => 'Saved', 'provider' => 'github', 'token' => $secret]);
    break;
default:
 $sites->save(self::input($secret));
    break;
}

    }
}
