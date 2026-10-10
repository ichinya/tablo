<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use PDO;
use Tablo\Auth;
use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordTest
{
    private static function reset(WebFixture $web): array
    {
        return Subprocess::run([PHP_BINARY, dirname(__DIR__, 2) . '/bin/admin-password.php', '--password-stdin'],
            $web->directory, ['TABLO_DB' => $web->directory->path . '/test.sqlite'], input: "replacement-secret\nreplacement-secret\n");
    }

    private static function login(WebFixture $web, #[\SensitiveParameter] string $password): array
    {
        return $web->request('/login', ['_csrf' => WebFixture::csrf($web->request('/login')), 'password' => $password]);
    }

    #[Test]
    public function resetRevokesEveryProtectedRouteAndPreservesSetupAndData(): void
    {
        $web = new WebFixture();
        $db = null;
        try {
            $csrf = $web->authenticate();
            $db = $web->database();
            $web->request('/settings/tokens/new', ['_csrf' => $csrf, 'name' => 'Synthetic token', 'provider' => 'github', 'token' => 'fixture-token']);
            $web->request('/sites/new', ['_csrf' => $csrf, 'name' => 'Synthetic site', 'url' => 'https://example.com',
                'repository' => 'fixture/private', 'github_token' => 'fixture-token', 'branch' => 'main', 'enabled' => '1',
                'comparison_mode' => 'release', 'health_path' => '/up', 'version_path' => '', 'sort_order' => '0']);
            $db->exec("UPDATE sites SET online=1, deployed_version='synthetic-version', deployed_commit='synthetic-commit',
                latest_release='synthetic-release', latest_commit='synthetic-latest', open_issues=3, open_prs=2,
                response_time_ms=42, last_error='synthetic-error', checked_at='2026-10-09T00:00:00Z',
                health_error_code='http_error', health_http_status=503");
            $tables = [];
            foreach (['sites', 'git_tokens', 'login_limits'] as $table) { $tables[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(); }
            Assert::same(count($tables['sites']), 1);
            Assert::same(count($tables['git_tokens']), 1);
            $keyPath = $web->directory->path . '/github-token.key';
            $key = file_get_contents($keyPath);
            $created = $db->query('SELECT created_at FROM users')->fetchColumn();
            $oldHash = $db->query('SELECT password_hash FROM users')->fetchColumn();
            Assert::same(self::reset($web)['exit_code'], 0);
            foreach ($tables as $table => $rows) { Assert::same($db->query('SELECT * FROM ' . $table)->fetchAll(), $rows); }
            Assert::same(file_get_contents($keyPath), $key);
            Assert::same($db->query('SELECT created_at FROM users')->fetchColumn(), $created);
            $vault = \Tablo\TokenVault::forDatabase($db);
            Assert::same($vault->decrypt($tables['git_tokens'][0]['encrypted_token']), 'fixture-token');
            Assert::same($vault->decrypt($tables['sites'][0]['github_token']), 'fixture-token');
            $vault = null;
            foreach ([true, false] as $cookie) {
                foreach ([null, [], ['_csrf' => $csrf, 'password' => 'replacement-secret', 'confirmation' => 'replacement-secret']] as $data) {
                    Assert::same($web->request('/setup', $data, $cookie)['status'], 404);
                }
            }
            foreach (['/', '/settings', '/sites/new', '/sites/1/edit', '/sites/1/delete', '/settings/tokens/new',
                '/settings/tokens/1/edit', '/settings/tokens/1/delete'] as $route) {
                $result = $web->request($route);
                Assert::same($result['status'], 303);
                Assert::true(str_contains($result['headers'], 'Location: /login'));
            }
            foreach (['/sites/new', '/sites/branches', '/sites/1/edit', '/sites/1/delete', '/sites/1/check',
                '/settings/tokens/new', '/settings/tokens/1/edit', '/settings/tokens/1/delete', '/logout'] as $route) {
                Assert::same($web->request($route, ['_csrf' => $csrf, 'name' => 'hostile mutation'])['status'], 303);
            }
            foreach (['sites', 'git_tokens'] as $table) { Assert::same($db->query('SELECT * FROM ' . $table)->fetchAll(), $tables[$table]); }
            Assert::same(self::login($web, 'fixture-password')['status'], 422);
            Assert::same(self::login($web, 'replacement-secret')['status'], 303);
            Assert::same($web->request('/')['status'], 200);
            Assert::true(WebFixture::csrf($web->request('/')) !== $csrf);
            foreach (glob($web->directory->path . '/runtime/sessions/sess_*') as $session) {
                $contents = file_get_contents($session);
                Assert::true(!str_contains($contents, $oldHash) && !str_contains($contents, 'replacement-secret'));
            }
        } finally { $db = null; $web->close(); }
    }

    #[Test]
    public function setupIsAlwaysClosedDuringActualWriterAndRollback(): void
    {
        $web = new WebFixture();
        $db = null;
        try {
            $csrf = $web->authenticate();
            $db = $web->database();
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $db->exec('BEGIN IMMEDIATE');
            $snapshot = (new Auth($db))->credentialFingerprint();
            // Writer retains id=1; another connection sees its committed old row.
            $db->prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([password_hash('replacement-secret', PASSWORD_DEFAULT)]);
            foreach ([true, false] as $cookie) {
                foreach ([null, [], ['_csrf' => $csrf]] as $data) { Assert::same($web->request('/setup', $data, $cookie)['status'], 404); }
            }
            Assert::same($web->request('/')['status'], 200, 'old committed snapshot remains admitted during writer');
            $db->exec('ROLLBACK');
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            Assert::same((new Auth($db))->credentialFingerprint(), $snapshot);
            $db->exec("CREATE TRIGGER block_reset BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
            Assert::same(self::reset($web)['exit_code'], 1);
            Assert::same($web->request('/')['status'], 200);
            Assert::same($web->request('/setup', ['_csrf' => $csrf])['status'], 404);
        } finally {
            if ($db?->inTransaction()) { $db->rollBack(); }
            $db = null; $web->close();
        }
    }

    private static function sessionFile(WebFixture $web): string
    {
        $files = glob($web->directory->path . '/runtime/sessions/sess_*');
        Assert::same(count($files), 1, 'one owned browser session');
        return $files[0];
    }

    #[Test]
    public function legacyAndHostileMarkersFailClosedAndRotateCsrf(): void
    {
        $web = new WebFixture();
        try {
            $csrf = $web->authenticate();
            foreach ([null, 123, [], false, 'malformed', str_repeat('a', 64)] as $marker) {
                $file = self::sessionFile($web);
                $contents = 'authenticated_at|i:' . time() . ';csrf|' . serialize($csrf);
                if ($marker !== null) { $contents .= 'auth_fingerprint|' . serialize($marker); }
                file_put_contents($file, $contents);
                Assert::same($web->request('/setup', ['_csrf' => $csrf])['status'], 404);
                Assert::same($web->request('/')['status'], 303);
                $newCsrf = WebFixture::csrf($web->request('/login'));
                Assert::true($newCsrf !== $csrf);
                Assert::same($web->request('/login', ['_csrf' => $csrf, 'password' => 'fixture-password'])['status'], 419);
                Assert::same($web->request('/login', ['_csrf' => $newCsrf, 'password' => 'fixture-password'])['status'], 303);
                $csrf = WebFixture::csrf($web->request('/'));
            }
            $file = self::sessionFile($web);
            $contents = file_get_contents($file);
            file_put_contents($file, preg_replace('/authenticated_at\|i:\d+;/', 'authenticated_at|i:' . (time() - 43201) . ';', $contents));
            Assert::same($web->request('/')['status'], 303);
            Assert::true(!str_contains(file_get_contents(self::sessionFile($web)), 'auth_fingerprint'));
            Assert::same(self::login($web, 'fixture-password')['status'], 303);
            $csrf = WebFixture::csrf($web->request('/'));
            Assert::same($web->request('/logout', ['_csrf' => $csrf])['status'], 303);
            Assert::same($web->request('/')['status'], 303);
            Assert::true(!str_contains(file_get_contents(self::sessionFile($web)), 'auth_fingerprint'));
        } finally { $web->close(); }
    }

    #[Test]
    public function verifiedOldLoginCannotAcquireTheReplacementSnapshot(): void
    {
        $this->loginRace(false);
    }

    #[Test]
    public function rehashCallerCannotAcquireAConcurrentResetSnapshot(): void
    {
        $this->loginRace(true);
    }

    private function loginRace(bool $rehash): void
    {
        $barrier = new TemporaryDirectory('tablo-login-barrier-');
        $web = null;
        $process = null;
        $pipes = [];
        try {
            $web = new WebFixture(router: dirname(__DIR__) . '/password-race-router.php', environment: ['TABLO_TEST_AUTH_BARRIER' => $barrier->path]);
            $csrf = $web->authenticate();
            $web->request('/logout', ['_csrf' => $csrf]);
            $csrf = WebFixture::csrf($web->request('/login'));
            if ($rehash) {
                $web->database()->prepare('UPDATE users SET password_hash=? WHERE id=1')
                    ->execute([password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4])]);
            }
            file_put_contents($barrier->path . '/armed', 'ready');
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/password-login.php', $web->server->base,
                $web->directory->path . '/cookies', $csrf], [0 => ['pipe', 'r'], 1 => ['file', $barrier->path . '/out', 'w'],
                2 => ['file', $barrier->path . '/err', 'w']], $pipes, dirname(__DIR__, 2));
            if (!is_resource($process)) { throw new \RuntimeException('Cannot start HTTP login fixture'); }
            fclose($pipes[0]);
            $deadline = microtime(true) + 5;
            while (!is_file($barrier->path . '/verified') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
            Assert::true(is_file($barrier->path . '/verified'), 'actual old login returned verified snapshot');
            Assert::same(self::reset($web)['exit_code'], 0);
            unlink($barrier->path . '/armed');
            file_put_contents($barrier->path . '/release', 'ready');
            Assert::same(proc_close($process), 0);
            $process = null;
            Assert::same($web->request('/')['status'], 303, 'late cookie is stale');
            Assert::same(self::login($web, 'fixture-password')['status'], 422);
            Assert::same(self::login($web, 'replacement-secret')['status'], 303);
            Assert::same($web->request('/')['status'], 200);
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            $web?->close(); $barrier->close();
        }
    }

    #[Test]
    public function actualWriterDeathBeforeCommitAndOutputFailureAfterCommitHaveDifferentOutcomes(): void
    {
        $web = new WebFixture();
        $db = null;
        $process = null;
        try {
            $csrf = $web->authenticate();
            $db = $web->database();
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $process = new PasswordProcess($web->directory, $web->directory->path . '/test.sqlite',
                dirname(__DIR__) . '/fixtures/password-write-barrier.php', ['TABLO_TEST_WRITE_BARRIER' => $web->directory->path]);
            $process->send("replacement-secret\nreplacement-secret\n");
            $written = $web->directory->path . '/written';
            $deadline = microtime(true) + 3;
            while (!file_exists($written) && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
            Assert::true(file_exists($written), 'actual CLI verified its uncommitted UPDATE before COMMIT');
            Assert::same($web->request('/setup', ['_csrf' => $csrf])['status'], 404);
            $process->close(); $process = null;
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            Assert::same($web->request('/')['status'], 200);
            $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/password-output-failure.php', '--password-stdin'],
                $web->directory, ['TABLO_DB' => $web->directory->path . '/test.sqlite'], input: "replacement-secret\nreplacement-secret\n");
            Assert::same($result['exit_code'], 1);
            Assert::true(!str_contains($result['stdout'], 'Administrator password changed.'));
            Assert::true(str_contains($result['stderr'], 'outcome may be uncertain'));
            Assert::true(password_verify('replacement-secret', $db->query('SELECT password_hash FROM users')->fetchColumn()));
            Assert::same($web->request('/')['status'], 303);
            Assert::same(self::login($web, 'replacement-secret')['status'], 303);
            Assert::same($web->request('/setup')['status'], 404);
        } finally {
            $process?->close();
            if ($db?->inTransaction()) { $db->rollBack(); }
            $db = null; $web->close();
        }
    }
}
