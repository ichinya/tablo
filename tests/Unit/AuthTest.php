<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Auth;
use Tablo\Database;
use Tablo\ClientAddress;
use Tablo\ValidationException;
use Tablo\Tests\Support\TemporaryDirectory;

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
        UnitFixtures::rejects(fn () => $auth->setup('short', 'short'), 'short password accepted');
        UnitFixtures::rejects(fn () => $auth->setup('fixture-password', 'different'), 'mismatch accepted');
        $auth->setup('fixture-password', 'fixture-password');
        Assert::true(!$auth->needsSetup(), 'setup repeated');
        $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
        Assert::true($hash !== 'fixture-password' && password_verify('fixture-password', $hash), 'password not hashed');
        UnitFixtures::rejects(fn () => $auth->setup('another-password', 'another-password'), 'second administrator accepted');
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
        UnitFixtures::rejects(fn () => (new Auth($db))->login('fixture-password', 'client'), 'rate limit bypassed');
        Assert::true($auth->login('fixture-password', 'other-client'), 'independent client blocked');
        $db->exec('UPDATE login_limits SET window_start = 0');
        Assert::true($auth->login('fixture-password', 'client'), 'rate limit never expires');
    }

    #[Test]
    public function canonicalKeysPersistAcrossConnectionsAndSuccessOnlyResetsItsOwnWindow(): void
    {
        $credential = bin2hex(random_bytes(16));
        $directory = new TemporaryDirectory('tablo-auth-proxy-');
        $db = $auth = null;
        try {
            $path = $directory->path . '/auth.sqlite';
            $db = Database::connect($path);
            $auth = new Auth($db);
            $auth->setup($credential, $credential);
            $auth = $db = null;
            $resolver = new ClientAddress('192.0.2.10');
            foreach (['2001:0DB8:0:0:0:0:0:0042', '2001:db8::42', '2001:DB8::42',
                '2001:0db8:0000:0000:0000:0000:0000:0042', '2001:db8::42'] as $header) {
                $db = Database::connect($path);
                $auth = new Auth($db);
                $address = $resolver->resolve(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => $header]);
                Assert::true(!$auth->login('incorrect', $address));
                $auth = $db = null;
            }
            $db = Database::connect($path);
            $auth = new Auth($db);
            $rejected = false;
            try { $auth->login($credential, '2001:db8::42'); } catch (ValidationException) { $rejected = true; }
            Assert::true($rejected, 'canonical rate limit bypassed');
            Assert::true(!$auth->login('incorrect', '2001:db8::43'));
            Assert::true(!$auth->login('incorrect', '2001:db8::43'));
            Assert::true($auth->login($credential, '2001:db8::43'));
            Assert::same($db->query('SELECT key, failures FROM login_limits')->fetchAll(), [
                ['key' => hash('sha256', '2001:db8::42'), 'failures' => 5],
            ]);
            $db->exec('UPDATE login_limits SET window_start = ' . (time() - 899));
            $rejected = false;
            try { $auth->login($credential, '2001:db8::42'); } catch (ValidationException) { $rejected = true; }
            Assert::true($rejected, 'window expired early');
            $db->exec('UPDATE login_limits SET window_start = ' . (time() - 901));
            Assert::true($auth->login($credential, '2001:db8::42'));
            Assert::same((int) $db->query('SELECT COUNT(*) FROM login_limits')->fetchColumn(), 0);
        } finally { $auth = $db = null; $directory->close(); }
    }

    #[Test]
    public function failedPersistentWriteRollsBackExpiredRowCleanupAndReleasesTheTransaction(): void
    {
        $credential = bin2hex(random_bytes(16));
        $directory = new TemporaryDirectory('tablo-auth-rollback-');
        $db = $auth = null;
        try {
            $path = $directory->path . '/auth.sqlite';
            $db = Database::connect($path);
            $auth = new Auth($db);
            $auth->setup($credential, $credential);
            $db->exec("INSERT INTO login_limits VALUES ('expired-sentinel', 3, 0)");
            $db->exec("CREATE TRIGGER fixture_write_failure BEFORE INSERT ON login_limits BEGIN SELECT RAISE(ABORT, 'fixture write failure'); END");
            $failed = false;
            // Do not retain the exception/trace (and its PDO) during Windows cleanup.
            try { $auth->login('incorrect', '198.51.100.42'); } catch (\PDOException) { $failed = true; }
            Assert::true($failed, 'Forced write failure was not exercised');
            Assert::same($db->query('SELECT key, failures FROM login_limits')->fetchAll(), [
                ['key' => 'expired-sentinel', 'failures' => 3],
            ]);
            $auth = $db = null;
            $db = Database::connect($path);
            $db->exec('DROP TRIGGER fixture_write_failure');
            $auth = new Auth($db);
            Assert::true(!$auth->login('incorrect', '198.51.100.42'));
            Assert::true($auth->login($credential, '198.51.100.42'));
            Assert::same((int) $db->query('SELECT COUNT(*) FROM login_limits')->fetchColumn(), 0);
        } finally { $auth = $db = null; $directory->close(); }
    }
}
