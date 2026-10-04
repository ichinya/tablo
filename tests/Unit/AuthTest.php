<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Auth;
use Tablo\Database;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\UnitFixtures;

final class AuthTest
{
    #[Test]
    public function createsOneAdministratorWithHashedCredentials(): void
    {
        $db = Database::connect(':memory:');
        $auth = new Auth($db);
        Assert::true($auth->needsSetup(), 'setup required');
        UnitFixtures::rejects(static fn () => $auth->setup('short', 'short'), 'short password accepted');
        UnitFixtures::rejects(static fn () => $auth->setup('fixture-password', 'different'), 'mismatch accepted');
        $auth->setup('fixture-password', 'fixture-password');
        Assert::true(!$auth->needsSetup(), 'setup repeated');
        $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
        // Assert exact fixture values/ciphertext; this is not an authentication decision.
        // @mago-expect lint:no-insecure-comparison
        Assert::true($hash !== 'fixture-password' && password_verify('fixture-password', $hash), 'password not hashed');
        UnitFixtures::rejects(static fn () => $auth->setup('another-password', 'another-password'), 'second administrator accepted');
        Assert::true($auth->login('fixture-password', 'client'), 'valid login refused');
    }

    #[Test]
    public function persistsRateLimitsAndExpiresThem(): void
    {
        $db = Database::connect(':memory:');
        $auth = new Auth($db);
        $auth->setup('fixture-password', 'fixture-password');
        for ($i = 0; $i < 5; ++$i) {
            Assert::true(!(new Auth($db))->login('incorrect', 'client'), 'wrong password accepted');
        }
        UnitFixtures::rejects(static fn () => (new Auth($db))->login('fixture-password', 'client'), 'rate limit bypassed');
        Assert::true($auth->login('fixture-password', 'other-client'), 'independent client blocked');
        $db->exec('UPDATE login_limits SET window_start = 0');
        Assert::true($auth->login('fixture-password', 'client'), 'rate limit never expires');
    }
}
