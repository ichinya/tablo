<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class ReadApiTokenManagementTest
{
    #[Test]
    public function nativeBcryptVariantsUpgradeTheirExactStoredBytesWithoutChangingTheBearerRow(): void
    {
        foreach (['2y', '2a', '2b'] as $prefix) {
            $fixture = new ReadApiFixture(); $web = $fixture->web; $db = null;
            try {
                $csrf = $web->authenticate(); $secret = $fixture->rotate($csrf); $db = $web->database();
                Assert::same($fixture->api(secret: $secret)['status'], 200);
                Assert::same($web->request('/logout', ['_csrf' => $csrf])['status'], 303);
                $password = str_repeat('x', times: 72);
                $legacy = '$' . $prefix . '$' . substr(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), offset: 4);
                Assert::true(password_verify($password, $legacy));
                Assert::true(password_verify($password . 'suffix', $legacy), 'native truncation control');
                \Tablo\SqlStatement::checked($db->prepare('UPDATE users SET password_hash=? WHERE id=1'))->execute([$legacy]);
                $fingerprint = \Tablo\AdministratorFingerprint::fingerprint($legacy);
                Assert::same((new \Tablo\Auth($db))->credentialFingerprint(), $fingerprint);
                \Tablo\SqlStatement::checked($db->prepare('UPDATE read_api_token SET administrator_fingerprint=?'))->execute([$fingerprint]);
                $row = A::rows($db, 'SELECT * FROM read_api_token');
                // Existing API ingress requires native metadata recognition; foreign variants remain refused there.
                Assert::same($fixture->api(secret: $secret)['status'], $prefix === '2y' ? 200 : 401);
                $loginCsrf = \Tablo\Tests\Support\WebFixture::csrf($web->request('/login'));
                foreach (['incorrect-password', $password . 'suffix'] as $refused) {
                    Assert::same($web->request('/login', ['_csrf' => $loginCsrf, 'password' => $refused])['status'], 422);
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), $legacy);
                    Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
                }
                Assert::same($web->request('/login', ['_csrf' => $loginCsrf, 'password' => $password])['status'], 303);
                $current = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
                Assert::same(password_get_info($current)['algoName'], 'argon2id');
                Assert::true(password_verify($password, $current));
                Assert::same((new \Tablo\Auth($db))->credentialFingerprint(), \Tablo\AdministratorFingerprint::fingerprint($current));
                Assert::same($web->request('/settings')['status'], 200);
                Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
                ReadApiFixture::error($fixture->api(secret: $secret), 401, 'unauthorized');
            } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
            finally { $db = null; $fixture->close(); }
        }
    }

    #[Test]
    public function realBcryptLoginUpgradeAndCliResetRevokeOldBearerWhileItsStoredRowStaysIdentical(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web; $db = null;
        try {
            $csrf = self::legacyBrowser($fixture); $secret = $fixture->rotate($csrf); $db = $web->database();
            $row = A::rows($db, 'SELECT * FROM read_api_token');
            $options = new \Tablo\Tests\Support\WebRequestOptions(jar: 'upgrade-cookies');
            $loginCsrf = \Tablo\Tests\Support\WebFixture::csrf($web->request('/login', options: $options));
            Assert::same($web->request('/login', ['_csrf' => $loginCsrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'wrong')], $options)['status'], 422);
            Assert::same($fixture->api(secret: $secret)['status'], 200);
            Assert::same($web->request('/login', ['_csrf' => $loginCsrf, 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')], $options)['status'], 303);
            $hash = \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn());
            Assert::same(password_get_info($hash)['algoName'], 'argon2id');
            ReadApiFixture::error($fixture->api(secret: $secret), 401, 'unauthorized');
            Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
            foreach (['rotate', 'revoke'] as $action) { Assert::same($web->request('/settings/read-api-token/' . $action, ['_csrf' => $csrf])['status'], 303); }
            Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
            $freshCsrf = \Tablo\Tests\Support\WebFixture::csrf($web->request('/settings', options: $options));
            copy($web->directory->path . '/upgrade-cookies', $web->directory->path . '/cookies');
            $fresh = $fixture->rotate($freshCsrf); Assert::same($fixture->api(secret: $fresh)['status'], 200);
            $row = A::rows($db, 'SELECT * FROM read_api_token');
            $result = \Tablo\Tests\Support\Subprocess::run(command: [PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/admin-password.php', '--password-stdin'],
                directory: $web->directory, environment: ['TABLO_DB' => $web->directory->path . '/test.sqlite'],
                options: new \Tablo\Tests\Support\CommandOptions(input: \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret')));
            Assert::same($result['exit_code'], 0);
            ReadApiFixture::error($fixture->api(secret: $fresh), 401, 'unauthorized');
            Assert::same(A::rows($db, 'SELECT * FROM read_api_token'), $row);
            Assert::same($web->request('/settings/read-api-token/rotate', ['_csrf' => $freshCsrf])['status'], 303);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $db = null; $fixture->close(); }
    }

    private static function legacyBrowser(ReadApiFixture $fixture): string
    {
        $web = $fixture->web; $csrf = $web->authenticate(); $db = $web->database();
        $old = \Tablo\ReadApiTokenRepository::administrator($db); Assert::notNull($old);
        $legacy = password_hash(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), PASSWORD_BCRYPT, ['cost' => 4]);
        \Tablo\SqlStatement::checked($db->prepare('UPDATE users SET password_hash=? WHERE id=1'))->execute([$legacy]);
        $selected = \Tablo\ReadApiTokenRepository::administrator($db); Assert::notNull($selected);
        $files = glob($web->directory->path . '/runtime/sessions/sess_*');
        if ($files === false) { throw new \RuntimeException('Cannot enumerate owned legacy session fixture.'); }
        Assert::same(count($files), 1);
        $file = \Tablo\Tests\Support\FixtureValue::at($files, 0);
        $replacements = 0;
        $contents = str_replace($old, $selected, \Tablo\RequiredFile::bytes(file_get_contents($file)), $replacements);
        Assert::same($replacements, 1, 'independently seed a cookie bound to the exact bcrypt credential');
        file_put_contents($file, $contents);
        Assert::same($web->request('/settings')['status'], 200, 'legacy cookie is valid before the upgrade');
        return $csrf;
    }

    #[Test]
    public function lostRevealAfterCommittedRotationRequiresAnotherRotationAndNeverReturnsTheSecret(): void
    {
        $source = new \Tablo\Tests\Support\TemporaryDirectory('tablo-read-api-reveal-'); $fixture = null; $tokens = null;
        try {
            mkdir($source->path . '/app');
            $root = dirname(__DIR__, levels: 2); $pageSource = $source->path . '/app/ReadApiTokenPages.php';
            copy($root . '/app/ReadApiTokenPages.php', $pageSource);
            Assert::same(hash_file('sha256', $pageSource), hash_file('sha256', $root . '/app/ReadApiTokenPages.php'));
            $fixture = new ReadApiFixture(['TABLO_TEST_READ_API_PAGE_SOURCE' => $pageSource]); $web = $fixture->web;
            $csrf = $web->authenticate(); $tokens = new \Tablo\ReadApiTokenRepository($web->database());
            $fingerprint = \Tablo\ReadApiTokenRepository::administrator($web->database()); Assert::notNull($fingerprint);
            $old = $tokens->rotate($fingerprint, static fn (): bool => true);
            $before = \Tablo\ReadApiTokenRepository::stored($web->database()); Assert::notNull($before);
            $response = $web->request('/settings/read-api-token/rotate', ['_csrf' => $csrf]);
            Assert::same($response['status'], 409); Assert::false(str_contains($response['body'], 'tablo_read_v1_'));
            $after = \Tablo\ReadApiTokenRepository::stored($web->database()); Assert::notNull($after);
            Assert::false(hash_equals($before['secret_hash'], $after['secret_hash'])); Assert::true($tokens->metadata()['active']);
            ReadApiFixture::error($fixture->api(secret: $old), 401, 'unauthorized');
            mkdir($source->path . '/views'); copy($root . '/views/read-api-token-issued.volt', $source->path . '/views/read-api-token-issued.volt');
            $replacement = $fixture->rotate($csrf); Assert::same($fixture->api(secret: $replacement)['status'], 200);
            Assert::false(str_contains($web->request('/settings')['body'], $replacement));
            Assert::false(str_contains($web->server->diagnostics(), $replacement));
        } catch (\Throwable $error) {
            $source->retainFailure($error); $fixture?->web->directory->retainFailure($error); throw $error;
        } finally {
            $tokens = null;
            $source->cleanup(static function () use ($fixture, $source): void { $fixture?->close(); $source->close(); });
        }
    }

    #[Test]
    public function realCookieCsrfRotationOneTimeRevealRevocationAndPasswordChangeRespectSeparatedAuthority(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web; $db = null;
        try {
            Assert::same($web->request('/settings/read-api-token/rotate', ['_csrf' => 'none'])['status'], 303);
            $csrf = $web->authenticate(); $before = A::snapshot($web->database());
            foreach (['rotate','revoke'] as $action) { Assert::same($web->request('/settings/read-api-token/' . $action, ['_csrf' => 'wrong'])['status'], 419); }
            Assert::same(A::snapshot($web->database()), $before);
            $secret = $fixture->rotate($csrf); Assert::same($fixture->api(secret: $secret)['status'], 200);
            foreach (['/settings','/'] as $path) { Assert::false(str_contains($web->request($path)['body'], $secret)); }
            $replacement = $fixture->rotate($csrf); ReadApiFixture::error($fixture->api(secret: $secret), 401, 'unauthorized');
            Assert::same($fixture->api(secret: $replacement)['status'], 200);
            Assert::same($web->request('/settings/read-api-token/revoke', ['_csrf' => $csrf])['status'], 303);
            ReadApiFixture::error($fixture->api(secret: $replacement), 401, 'unauthorized');
            $replacement = $fixture->rotate($csrf);
            $statement = \Tablo\SqlStatement::checked($web->database()->prepare('UPDATE users SET password_hash=? WHERE id=1'));
            try { $statement->execute([password_hash('synthetic-new-read-api-password', PASSWORD_DEFAULT)]); } finally { $statement->closeCursor(); }
            unset($statement);
            ReadApiFixture::error($fixture->api(secret: $replacement), 401, 'unauthorized');
            Assert::same($web->request('/settings/read-api-token/rotate', ['_csrf' => $csrf])['status'], 303);
            Assert::false(str_contains($web->server->diagnostics(), $secret));
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function actualDeferredCommitFailureKeepsOldTokenAndCannotRevealUncommittedReplacement(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web; $db = null;
        try {
            $csrf = $web->authenticate(); $secret = $fixture->rotate($csrf); $db = $web->database();
            $db->exec('CREATE TABLE token_commit_control(parent INTEGER REFERENCES users(id) DEFERRABLE INITIALLY DEFERRED)');
            $db->exec('CREATE TRIGGER fail_token_commit AFTER UPDATE ON read_api_token BEGIN INSERT INTO token_commit_control VALUES(2); END');
            $response = $web->request('/settings/read-api-token/rotate', ['_csrf' => $csrf]);
            Assert::same($response['status'], 409); Assert::false(str_contains($response['body'], 'tablo_read_v1_'));
            Assert::same($fixture->api(secret: $secret)['status'], 200); Assert::same(A::rows($db, 'SELECT * FROM token_commit_control'), []);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { unset($db); $fixture->close(); }
    }
}
