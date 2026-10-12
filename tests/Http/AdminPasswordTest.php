<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


use Tablo\Auth;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordTest extends PasswordHttpFixtureCase
{


    #[Test]
    public function resetRevokesEveryProtectedRouteAndPreservesSetupAndData(): void
    {
        $web = new WebFixture();
        $db = null;
        try {
            $csrf = $web->authenticate();
            $db = $web->database();
            $web->request('/settings/tokens/new', ['_csrf' => $csrf, 'name' => 'Synthetic token', 'provider' => 'github', 'token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token')]);
            $web->request('/sites/new', ['_csrf' => $csrf, 'name' => 'Synthetic site', 'url' => 'https://example.com',
                'repository' => 'fixture/private', 'github_token' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'), 'branch' => 'main', 'enabled' => '1',
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
            Assert::same($vault->decrypt($tables['git_tokens'][0]['encrypted_token']), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'));
            Assert::same($vault->decrypt($tables['sites'][0]['github_token']), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'));
            $vault = null;
            (new ProtectedRouteRevocationAssertions($web, $db))->all($csrf, $tables);
            Assert::same(self::login($web, \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'))['status'], 422);
            Assert::same(self::login($web, \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'))['status'], 303);
            Assert::same($web->request('/')['status'], 200);
            Assert::true(WebFixture::csrf($web->request('/')) !== $csrf);
            foreach (glob($web->directory->path . '/runtime/sessions/sess_*') as $session) {
                $contents = file_get_contents($session);
                Assert::true(!str_contains($contents, $oldHash) && !str_contains($contents, \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret')));
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
            $db->prepare('UPDATE users SET password_hash=? WHERE id=1')->execute([password_hash(\Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-secret'), PASSWORD_DEFAULT)]);
            foreach ([true, false] as $cookie) {
                foreach ([null, [], ['_csrf' => $csrf]] as $data) { Assert::same($web->request(path: '/setup', data: $data, options: new \Tablo\Tests\Support\WebRequestOptions(cookies: ($cookie) ? \Tablo\Tests\Support\CookiePolicy::Retained : \Tablo\Tests\Support\CookiePolicy::Omitted))['status'], 404); }
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
}
