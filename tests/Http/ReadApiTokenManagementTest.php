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
