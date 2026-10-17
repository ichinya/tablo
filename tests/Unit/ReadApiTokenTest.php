<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ReadApiToken;
use Tablo\ReadApiTokenRepository;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class ReadApiTokenTest
{
    #[Test]
    public function actualLoginUpgradeAndResetInvalidateBearerWithoutTouchingTheCompleteTokenRow(): void
    {
        $db = A::database(); $tokens = new ReadApiTokenRepository($db); $auth = new \Tablo\Auth($db);
        $legacy = password_hash('synthetic-api-migration', PASSWORD_BCRYPT, ['cost' => 4]);
        \Tablo\SqlStatement::checked($db->prepare('UPDATE users SET password_hash=? WHERE id=1'))->execute([$legacy]);
        $selected = ReadApiTokenRepository::administrator($db); Assert::notNull($selected);
        $secret = $tokens->rotate($selected, static fn (): bool => true);
        $row = A::rows($db, 'SELECT * FROM read_api_token');
        Assert::false($auth->login('incorrect', 'wrong'));
        Assert::true($tokens->authenticate($secret)); Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
        $db->exec("CREATE TRIGGER refuse_api_upgrade BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT,'fixture-refusal'); END");
        $failed = false;
        try { $auth->loginSession('synthetic-api-migration', 'rollback'); } catch (\PDOException) { $failed = true; }
        Assert::true($failed); Assert::true($tokens->authenticate($secret));
        Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
        $db->exec('DROP TRIGGER refuse_api_upgrade');
        Assert::notNull($auth->loginSession('synthetic-api-migration', 'upgrade'));
        Assert::false($tokens->authenticate($secret)); Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
        A::reject(static fn () => $tokens->rotate($selected, static fn (): bool => true));
        A::reject(static fn () => $tokens->revoke($selected, static fn (): bool => true));
        Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
        $current = ReadApiTokenRepository::administrator($db); Assert::notNull($current);
        $fresh = $tokens->rotate($current, static fn (): bool => true); $row = A::rows($db, 'SELECT * FROM read_api_token');
        Assert::true($auth->login('synthetic-api-migration', 'no-op'));
        Assert::true($tokens->authenticate($fresh)); Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
        $auth->changePassword('synthetic-api-replacement', 'synthetic-api-replacement', $current);
        Assert::false($tokens->authenticate($fresh)); Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
        A::reject(static fn () => $tokens->rotate($current, static fn (): bool => true));
    }

    #[Test]
    public function sensitiveBearerAndRowBoundariesDoNotExposePlaintextInExceptionArguments(): void
    {
        $db = A::database(); $tokens = new ReadApiTokenRepository($db); $secret = ReadApiToken::generate();
        $original = \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            $db->exec('DROP TABLE read_api_token'); $trace = null;
            try { $tokens->authenticate($secret); } catch (\Throwable $error) { $trace = json_encode($error->getTrace(), JSON_THROW_ON_ERROR); }
            if (!is_string($trace)) { throw new \RuntimeException('Expected native token-read exception was not reached.'); }
            Assert::false(str_contains($trace, $secret));
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: $original === false ? '1' : $original); }
        $db = null;
    }

    #[Test]
    public function defaultOffHashOnlyRotationRevocationAndCredentialBindingAreIndependentOfSessions(): void
    {
        $db = A::database(); $tokens = new ReadApiTokenRepository($db);
        Assert::same($tokens->metadata(), ['present' => false, 'active' => false, 'created_at' => null]);
        $fingerprint = ReadApiTokenRepository::administrator($db); Assert::notNull($fingerprint);
        $secret = $tokens->rotate($fingerprint, static fn (): bool => true);
        Assert::same(preg_match('/^tablo_read_v1_[0-9a-f]{64}$/D', $secret), 1);
        Assert::true($tokens->authenticate($secret));
        $rows = A::rows($db, 'SELECT * FROM read_api_token');
        Assert::false(str_contains(json_encode($rows, JSON_THROW_ON_ERROR), $secret));
        $stored = \Tablo\Tests\Support\FixtureValue::string(($rows[0] ?? [])['secret_hash'] ?? null);
        Assert::true(hash_equals($stored, ReadApiToken::digest($secret)));
        Assert::false(hash_equals($stored, hash('sha256', $secret)));
        $replacement = $tokens->rotate($fingerprint, static fn (): bool => true);
        Assert::false($tokens->authenticate($secret)); Assert::true($tokens->authenticate($replacement));
        $statement = \Tablo\SqlStatement::checked($db->prepare('UPDATE users SET password_hash=? WHERE id=1'));
        try { $statement->execute([password_hash('synthetic-read-api-administrator', PASSWORD_DEFAULT)]); } finally { $statement->closeCursor(); }
        Assert::false($tokens->authenticate($replacement)); Assert::false($tokens->metadata()['active']);
        $current = ReadApiTokenRepository::administrator($db); Assert::notNull($current);
        $afterRehash = $tokens->rotate($current, static fn (): bool => true); Assert::true($tokens->authenticate($afterRehash));
        try { $statement->execute([password_hash('synthetic-changed-read-api-password', PASSWORD_DEFAULT)]); } finally { $statement->closeCursor(); }
        Assert::false($tokens->authenticate($afterRehash));
        $db = null;
    }

    #[Test]
    public function postLockAuthorityAndCommitFailureNeverRevealOrReplaceTheCommittedToken(): void
    {
        $db = A::database(); $tokens = new ReadApiTokenRepository($db); $fingerprint = ReadApiTokenRepository::administrator($db); Assert::notNull($fingerprint);
        $secret = $tokens->rotate($fingerprint, static fn (): bool => true); $before = A::snapshot($db);
        $reached = false;
        A::reject(static function () use ($tokens, $fingerprint, &$reached): void {
            $tokens->rotate($fingerprint, static function () use (&$reached): bool { $reached = true; return false; });
        });
        Assert::true($reached); Assert::same(A::snapshot($db), $before);
        A::reject(static fn () => $tokens->revoke(str_repeat('0', times: 64), static fn (): bool => true));
        Assert::same(A::snapshot($db), $before);
        $db->exec('CREATE TABLE deferred_token_failure(parent INTEGER REFERENCES users(id) DEFERRABLE INITIALLY DEFERRED)');
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('CREATE TRIGGER fail_token_commit AFTER UPDATE ON read_api_token BEGIN INSERT INTO deferred_token_failure VALUES(2); END');
        A::reject(static fn () => $tokens->rotate($fingerprint, static fn (): bool => true));
        Assert::true($tokens->authenticate($secret)); Assert::same(A::rows($db, 'SELECT * FROM deferred_token_failure'), []);
        $tokens->revoke($fingerprint, static fn (): bool => true); Assert::false($tokens->authenticate($secret));
        Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), []); $db = null;
    }
}
