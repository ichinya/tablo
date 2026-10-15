<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\GitTokenRepository;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;

final class SiteTokenRotationTest
{




    #[Test]
    public function guardsReusableTokensAndRotation(): void
    {
        $sample = UnitFixtures::site();
        $temporary = new TemporaryDirectory('tablo-saved-token-');
        $directory = $temporary->path;
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory . '/key');
            $tokens = new GitTokenRepository($db, $vault);
            $sites = new SiteRepository($db, $vault);
            $input = ['name' => 'Shared token', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')];
            $id = $tokens->save($input);
            $cipher = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT encrypted_token FROM git_tokens'))->fetchColumn());
            Assert::true(str_starts_with(\Tablo\Tests\Support\FixtureValue::string($cipher), 'v1:') && $cipher !== \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'), 'pool token stored in plaintext');
            Assert::true(!str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($tokens->all())), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) && !str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($tokens->find($id))), \Tablo\Tests\Support\FixtureValue::string($cipher)), 'pool metadata exposed token');
            (new SavedTokenAdmissionAssertions($tokens))->refuses($input);
            $poolSite = $sample + ['git_token_id' => (string) $id];
            $first = $sites->save($poolSite);
            $second = $sites->save(array_replace($poolSite, ['name' => 'Second']));
            $site = (new SavedTokenReferenceAssertions($sites, $tokens))->selected($sample, [$first, $id]);
            (new SavedTokenProviderAssertions($db, $sites))->refuses(\Tablo\Tests\Support\FixtureValue::string($cipher));
            $tokens->save(array_replace($input, ['name' => 'Renamed', 'token' => '']), $id);
            Assert::true($tokens->tokenFor($id, 'github') === \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token') && $sites->storeCheck($site, ['online' => 1, 'checked_at' => gmdate('c')]), 'metadata edit invalidated credential');
            $tokens->save(array_replace($input, ['name' => 'Renamed', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token')]), $id);
            Assert::true(\Tablo\Tests\Support\FixtureValue::row($sites->find($first))['checked_at'] === null && \Tablo\Tests\Support\FixtureValue::row($sites->find($second))['checked_at'] === null, 'rotation did not invalidate checks');
            Assert::true(!$sites->storeCheck($site, ['online' => 1]), 'in-flight check with old shared token survived');
            Assert::true($sites->tokenFor($sites->find($first)) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token'), 'rotation not used');
            (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => $tokens->delete($id))))->verify('used token deleted');
            (new SavedTokenSwitchAssertions($sites, $tokens))->manual($sample, [$id, $first, $second]);
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }
}
