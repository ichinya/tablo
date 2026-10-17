<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Auth;
use Tablo\Database;
use Tablo\PasswordHashPolicy;
use Tablo\PasswordService;
use Testo\Assert;
use Testo\Test;

final class PasswordHashPolicyTest
{
    #[Test]
    public function successfulLegacyUpgradeKeepsShortEmptyAndRawCredentialsVerifiable(): void
    {
        foreach (['', 'short', "\xFFraw\nbytes", str_repeat('x', times: 72)] as $password) {
            $db = Database::connect(':memory:');
            $legacy = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))->execute([$legacy]);
            $auth = new Auth($db); $old = $auth->credentialFingerprint();
            Assert::notNull($auth->loginSession($password, 'legacy'));
            $current = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
            Assert::same(password_get_info($current)['algoName'], 'argon2id');
            Assert::true(PasswordService::verify($password, $current));
            Assert::true($auth->credentialFingerprint() !== $old);
            Assert::same($auth->loginSession($password, 'unchanged'), $auth->credentialFingerprint());
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), $current);
        }
    }

    #[Test]
    public function replacementPreservesStrongerComponentsAndParallelismAloneDoesNotRewrite(): void
    {
        foreach ([['argon2id', 65_536, 3, 1, false], ['argon2id', 65_536, 3, 2, false],
            ['argon2id', 131_072, 4, 1, false], ['argon2id', 32_768, 4, 1, true],
            ['argon2id', 131_072, 2, 1, true], ['argon2i', 65_536, 3, 1, true]] as [$algorithm, $memory, $time, $threads, $replace]) {
            $password = bin2hex(random_bytes(16));
            $old = password_hash($password, $algorithm, ['memory_cost' => $memory, 'time_cost' => $time, 'threads' => $threads]);
            Assert::same(password_get_info($old)['options'], ['memory_cost' => $memory, 'time_cost' => $time, 'threads' => $threads], 'independent seed really exercises the selected costs');
            Assert::true(PasswordService::verify($password, $old));
            $db = Database::connect(':memory:');
            \Tablo\SqlStatement::checked($db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)'))->execute([$old]);
            $auth = new Auth($db);
            Assert::false($auth->login('incorrect', 'wrong'));
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), $old);
            Assert::true($auth->login($password, 'correct'));
            $current = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
            Assert::same($current !== $old, $replace);
            if (!$replace) { Assert::same($current, $old); continue; }
            Assert::same(password_get_info($current)['algoName'], 'argon2id');
            Assert::same(password_get_info($current)['options'], ['memory_cost' => max(65_536, $memory), 'time_cost' => max(3, $time), 'threads' => 1]);
            Assert::true(PasswordService::verify($password, $current));
        }
    }

    #[Test]
    public function malformedIndependentEncodingsCannotAuthorizeVerificationOrReplacement(): void
    {
        foreach (['', '$2y$00$' . str_repeat('a', times: 53), '$argon2id$v=16$m=65536,t=3,p=1$salt$digest',
            '$argon2id$v=19$m=0,t=3,p=1$salt$digest', '$argon2id$v=19$m=999999999999999999999,t=3,p=1$salt$digest',
            '$argon2id$v=19$m=65536,t=3,p=1$!$!', str_repeat('x', times: 513)] as $hash) {
            Assert::same(PasswordHashPolicy::storedAlgorithm($hash), null);
            Assert::same(PasswordHashPolicy::upgradeOptions($hash), null);
            Assert::false(PasswordService::verify('invalid-password', $hash));
        }
    }
}
