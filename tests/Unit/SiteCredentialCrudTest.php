<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\TokenVault;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;

final class SiteCredentialCrudTest
{



    #[Test]
    public function encryptsAndIsolatesProjectTokens(): void
    {
        $sample = UnitFixtures::site();
        $temporary = new TemporaryDirectory('tablo-token-');
        $directory = $temporary->path;
        try {
            $key = $directory . '/key';
            $vault = new TokenVault($key);
            $sites = new SiteRepository(Database::connect(':memory:'), $vault);
            $id = $sites->save($sample + ['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]);
            $site = $sites->find($id);
            Assert::true(str_starts_with($site['github_token'], 'v1:') && !str_contains($site['github_token'], \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')), 'plaintext stored');
            Assert::true($sites->tokenFor($site) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token') && filesize($key) === 32, 'token not restored');
            $other = $sites->save(array_replace($sample, ['name' => 'Second', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'other-token')]));
            Assert::true($sites->tokenFor($sites->find($other)) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'other-token') && $sites->tokenFor($site) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'), 'tokens crossed sites');
            Assert::true( hash_equals($sites->resolveToken([], $site), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')) , 'saved site token not used');
            Assert::true( hash_equals($sites->resolveToken(['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'new-token'), 'remove_github_token' => \Tablo\FormCheckbox::VALUE], $site), \Tablo\Tests\Support\FixtureCredentials::value(label: 'new-token')) , 'replacement lost');
            Assert::true($sites->resolveToken(['remove_github_token' => \Tablo\FormCheckbox::VALUE], $site) === '', 'removed token was reused');
            Assert::true($sites->resolveToken([], null) === '', 'credentials invented for a tokenless project');
            $sites->save($sample, $id);
            Assert::true( hash_equals($sites->find($id)['github_token'], $site['github_token']) , 'empty token erased saved token');
            $sites->save($sample + ['github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token')], $id);
            Assert::true(!$sites->storeCheck($site, ['online' => 1]), 'old check survived token-only replacement');
            Assert::true($sites->tokenFor($sites->find($id)) === \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token'), 'new token lost');
            $sites->save($sample + ['remove_github_token' => \Tablo\FormCheckbox::VALUE], $id);
            Assert::true($sites->find($id)['github_token'] === null, 'token not deleted');
            foreach (["token\r\nInjected: value", 'a b', str_repeat('a', times: 513), ['array']] as $bad) {
                (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => $sites->save($sample + ['github_token' => $bad]))))->verify('unsafe token accepted');
            }
            (new TokenTamperAssertions($vault))->refuses($key);
        } finally {
            unset($sites, $tokens, $db, $old);
            $temporary->close();
        }
    }
}
