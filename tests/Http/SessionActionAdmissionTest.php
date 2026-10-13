<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SchemaShape;
use Tablo\SettingsRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebRequestOptions;
use Testo\Assert;
use Testo\Test;

final class SessionActionAdmissionTest
{
    #[Test]
    public function admittedPausedManualPostRecordsActivityWithoutChecksAndCannotReviveExpiredCookies(): void
    {
        foreach (['idle', 'absolute'] as $boundary) {
            $directory = new TemporaryDirectory('tablo-paused-session-peer-');
            $peer = new TestServer($directory, dirname(__DIR__) . '/endpoint-router.php', environment: ['TABLO_HEALTH_FIXTURE' => $directory->path]);
            $fixture = new SessionFixture(true); $web = $fixture->web;
            try {
                $csrf = $web->authenticate();
                (new SettingsRepository($web->database()))->updateIdleTimeout($boundary === 'idle' ? '2' : '720');
                SqlStatement::checked($web->database()->prepare("INSERT INTO sites(id,name,url,repository,enabled)
                    VALUES(1,'Paused admission control',?,'fixture/public',0)"))->execute([$peer->base . '/']);
                $before = self::protectedRows($web->database());
                $fixture->clock(SessionFixture::START + 50);
                self::assertRefusedPausedRequests($fixture, $csrf);
                $fixture->clock(SessionFixture::START + 100);
                $response = $web->request('/sites/1/check', ['_csrf' => $csrf]);
                Assert::same($response['status'], 303);
                Assert::true(str_contains($response['headers'], 'Location: /' . "\r\n"));
                Assert::same($fixture->timestamps(), ['authenticated_at' => SessionFixture::START,
                    'last_activity_at' => SessionFixture::START + 100, 'session_observed_at' => SessionFixture::START + 100]);
                $expiry = SessionFixture::START + ($boundary === 'idle' ? 220 : 43_200);
                $fixture->clock($expiry - 1);
                Assert::same($web->request('/login')['status'], 303);
                Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START + 100);
                Assert::same($fixture->timestamps()['session_observed_at'], $expiry - 1);
                $fixture->clock($expiry);
                $expired = $web->request('/sites/1/check', ['_csrf' => $csrf]);
                Assert::same($expired['status'], 303);
                Assert::true(str_contains($expired['headers'], 'Location: /login' . "\r\n"));
                Assert::false(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($fixture->sessionFile())), 'last_activity_at'));
                Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
                Assert::false(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($fixture->sessionFile())), 'authenticated_at'));
                Assert::same(self::protectedRows($web->database()), $before);
                Assert::false(is_file($web->directory->path . '/github-calls.log'));
                Assert::false(is_file($directory->path . '/uris.jsonl'));
                Assert::false(is_file($web->directory->path . '/github-token.key'));
            } catch (\Throwable $error) { $web->directory->retainFailure($error); $directory->retainFailure($error); throw $error; }
            finally { $fixture->close(); $peer->close(); $directory->close(); }
        }
    }

    private static function assertRefusedPausedRequests(SessionFixture $fixture, string $csrf): void
    {
        $web = $fixture->web;
        foreach ([['/sites/1/check', ['_csrf' => 'wrong'], 419],
            ['/sites/1/check', ['_csrf' => $csrf, 'padding' => str_repeat('9', times: 17_000)], 413],
            ['/sites/999/check', ['_csrf' => $csrf], 404], ['/missing', ['_csrf' => $csrf], 404]] as [$path, $input, $status]) {
            Assert::same($web->request($path, $input)['status'], $status);
            Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
        }
        Assert::same($web->request('/sites/1/check')['status'], 404);
        Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf], options: new WebRequestOptions(jar: 'unauthenticated'))['status'], 303);
        Assert::same($fixture->timestamps()['last_activity_at'], SessionFixture::START);
    }

    #[Test]
    public function expiredCookieCannotWriteOrSpendProviderQuotaOrContactARealEnabledSite(): void
    {
        foreach (['idle', 'absolute'] as $boundary) {
            $directory = new TemporaryDirectory('tablo-session-peer-');
            $peer = new TestServer($directory, dirname(__DIR__) . '/endpoint-router.php', environment: ['TABLO_HEALTH_FIXTURE' => $directory->path]);
            $fixture = new SessionFixture(true); $web = $fixture->web;
            try {
                $csrf = $web->authenticate();
                $minutes = $boundary === 'idle' ? '2' : '720';
                (new SettingsRepository($web->database()))->updateIdleTimeout($minutes);
                SqlStatement::checked($web->database()->prepare("INSERT INTO sites(id,name,url,repository,enabled,health_path,version_path)
                    VALUES(1,'Enabled admission control',?,'fixture/public',1,'','')"))->execute([$peer->base . '/']);
                $before = self::protectedRows($web->database());
                $fixture->clock(SessionFixture::START + ($boundary === 'idle' ? 120 : 43_200));
                foreach (['/sites/1/check', '/sites/1/edit', '/sites/1/delete', '/sites/new', '/settings', '/settings/session-timeout',
                    '/settings/tokens/new', '/settings/tokens/1/edit', '/settings/tokens/1/delete', '/settings/notifications'] as $path) {
                    $status = $web->request($path, ['_csrf' => $csrf, 'name' => 'Forbidden mutation', 'check_interval_minutes' => '1',
                        'session_idle_timeout_minutes' => '1', 'repository' => 'fixture/public'])['status'];
                    Assert::same($status, 303, $boundary . ':' . $path);
                }
                Assert::same($web->request('/sites/branches', ['_csrf' => $csrf, 'site_id' => '1', 'repository' => 'fixture/public'])['status'], 401);
                $after = self::protectedRows($web->database());
                Assert::same($after, $before);
                Assert::false(is_file($web->directory->path . '/github-calls.log'));
                Assert::false(is_file($directory->path . '/uris.jsonl'));
                Assert::false(is_file($web->directory->path . '/github-token.key'));
            } catch (\Throwable $error) { $web->directory->retainFailure($error); $directory->retainFailure($error); throw $error; }
            finally { $fixture->close(); $peer->close(); $directory->close(); }
        }
    }

    /** @return array<string, list<array>> */
    private static function protectedRows(\PDO $db): array
    {
        $rows = [];
        foreach (['sites', 'git_tokens', 'installation_settings', 'github_cooldowns', 'check_history', 'incidents',
            'incident_checkpoints', 'notification_settings', 'notification_checkpoints', 'notification_slots', 'worker_runtime', 'worker_progress'] as $table) {
            $rows[$table] = SchemaShape::rows($db, 'SELECT * FROM ' . $table);
        }
        return $rows;
    }
}
