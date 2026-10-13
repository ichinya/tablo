<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SchemaShape;
use Tablo\SettingsRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\SessionFixture;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class SessionActionAdmissionTest
{
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
