<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseSession;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class EngineFlowTest
{
    #[Test]
    public function selectedEngineServesSetupCsrfCookieLoginAndLogout(): void
    {
        $fixture = new EngineFixture('flow-auth');
        $web = new WebFixture(environment: $fixture->environment());
        try {
            $setup = $web->request('/setup');
            Assert::same($setup['status'], 200);
            Assert::true(str_contains(strtolower($setup['headers']), 'httponly'));
            Assert::same($web->request('/setup', ['password' => FixtureCredentials::syntheticPassword(), 'confirmation' => FixtureCredentials::syntheticPassword()])['status'], 419);
            Assert::same($web->request('/setup', ['_csrf' => WebFixture::csrf($setup), 'password' => FixtureCredentials::syntheticPassword(),
                'confirmation' => FixtureCredentials::syntheticPassword()])['status'], 303);
            $home = $web->request('/'); Assert::same($home['status'], 200);
            Assert::true(WebFixture::csrf($home) !== WebFixture::csrf($setup));
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 1);
            Assert::same(DatabaseSession::get($web->database())?->configuration->engine, getenv('TABLO_TEST_DB_DRIVER'));
            Assert::same($web->request('/logout', ['_csrf' => WebFixture::csrf($home)])['status'], 303);
            Assert::same($web->request('/')['status'], 303);
            Assert::same($web->request('/dashboard/state')['status'], 401);
            $login = $web->request('/login');
            Assert::same($web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => FixtureCredentials::syntheticPassword()])['status'], 303);
            Assert::same($web->request('/setup')['status'], 404);
        } finally { $web->close(); $fixture->close(); }
    }

    #[Test]
    public function selectedEngineCommitsSiteTokenCrudAndSettingsWithRealForeignKeys(): void
    {
        $fixture = new EngineFixture('flow-crud');
        $web = new WebFixture(environment: $fixture->environment());
        try {
            $csrf = $web->authenticate();
            Assert::same($web->request('/settings/tokens/new', ['_csrf' => $csrf, 'name' => 'Synthetic token ', 'provider' => 'github',
                'token' => FixtureCredentials::value(label: 'engine-crud')])['status'], 303);
            $tokenId = $fixture->scalar('SELECT id FROM git_tokens');
            $site = ['_csrf' => $csrf, 'name' => 'Synthetic site ', 'url' => 'https://synthetic.invalid', 'repository' => 'synthetic/repo',
                'git_token_id' => (string) $tokenId, 'branch' => 'main', 'health_path' => '/up', 'health_check_mode' => 'http',
                'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
            Assert::same($web->request('/sites/new', $site)['status'], 303);
            $id = $fixture->scalar('SELECT id FROM sites');
            Assert::same($fixture->scalar('SELECT git_token_id FROM sites'), $tokenId);
            Assert::same($web->request('/sites/' . $id . '/edit', array_replace($site, ['name' => 'Changed site']))['status'], 303);
            Assert::same($fixture->scalar('SELECT config_revision FROM sites'), 1);
            Assert::same($web->request('/settings', ['_csrf' => $csrf, 'check_interval_minutes' => '17'])['status'], 303);
            Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings'), 17);
            $saved = $web->request('/dashboard/state');
            Assert::same($saved['status'], 200);
            $snapshot = \Tablo\JsonRecord::decode($saved['body']);
            Assert::same(\Tablo\SqlColumn::integer($snapshot, 'dashboard_version'), 1);
            Assert::same(\Tablo\SqlColumn::string($snapshot, 'csrf'), $csrf);
            $stats = \Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($snapshot, 'stats'));
            Assert::same(\Tablo\SqlColumn::integer($stats, 'total'), 1);
            Assert::true(str_contains(\Tablo\SqlColumn::string($snapshot, 'html'), 'Changed site'));
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 0);
            Assert::same($fixture->scalar('SELECT config_revision FROM sites'), 1);
            Assert::same($web->request('/sites/' . $id . '/delete', ['_csrf' => $csrf])['status'], 303);
            Assert::same($web->request('/settings/tokens/' . $tokenId . '/delete', ['_csrf' => $csrf])['status'], 303);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM sites'), 0);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM git_tokens'), 0);
        } finally { $web->close(); $fixture->close(); }
    }

    #[Test]
    public function selectedEngineManualCliAndWorkerChecksCommitTheSameHistoryPipeline(): void
    {
        $fixture = new EngineFixture('flow-checks');
        $web = new WebFixture(true, environment: $fixture->environment());
        $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-engine-endpoint-');
        $endpoint = null;
        try {
            $csrf = $web->authenticate();
            $endpoint = new \Tablo\Tests\Support\TestServer($directory, dirname(__DIR__) . '/fixtures/worker-router.php',
                environment: ['TABLO_WORKER_FIXTURE' => $directory->path]);
            $sites = new \Tablo\SiteRepository($fixture->database(), $fixture->vault);
            $id = $sites->save(array_replace(\Tablo\SiteInput::defaults(), ['name' => 'Engine application flow',
                'url' => $endpoint->base, 'repository' => 'fixture/project', 'health_path' => '/up', 'version_path' => '/version']));
            Assert::same($web->request('/sites/' . $id . '/check', ['_csrf' => $csrf])['status'], 303);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 1);
            Assert::same($fixture->scalar('SELECT online FROM sites'), 1);
            $root = dirname(__DIR__, levels: 2);
            $cli = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/FixtureCliGitHubConnection.php',
                $root . '/bin/check.php'], $directory, $fixture->environment() + ['TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                    'TABLO_TEST_GITHUB_LOG' => $directory->path . '/cli-calls.log']);
            Assert::same([$cli['exit_code'], $cli['stderr']], [0, '']);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 2);
            $http = new \Tablo\Tests\Support\WorkerHttp($endpoint->base);
            $messages = [];
            $hooks = new \Tablo\WorkerHooks(output: static function (string $message) use (&$messages): void { $messages[] = $message; });
            $worker = new \Tablo\PeriodicWorker($fixture->database(), $http, $hooks, vault: $fixture->vault);
            $worker->runPass();
            Assert::same($messages, ['Worker site checked.']);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 3);
            Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 3);
            Assert::same($fixture->scalar('SELECT COUNT(*) FROM incident_checkpoints'), 1);
            Assert::true(in_array('/up', $http->calls, strict: true));
            Assert::true(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($directory->path . '/requests.jsonl')), '/up'));
            Assert::same($web->request('/')['status'], 200);
        } finally { $endpoint?->close(); $web->close(); $fixture->close(); $directory->close(); }
    }

    #[Test]
    public function selectedEngineProtectedAdminCliInvalidatesThePreviousWebCredential(): void
    {
        $fixture = new EngineFixture('flow-admin');
        $web = new WebFixture(environment: $fixture->environment());
        try {
            $web->authenticate();
            $before = \Tablo\SqlColumn::string(\Tablo\ServerSchema::rows($fixture->database(), 'SELECT password_hash FROM users')[0] ?? [], 'password_hash');
            $replacement = FixtureCredentials::value(label: 'engine-new-administrator-password');
            $result = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/admin-password.php', '--password-stdin'],
                $web->directory, $fixture->environment(), new \Tablo\Tests\Support\CommandOptions(input: $replacement . "\n" . $replacement . "\n"));
            Assert::same([$result['exit_code'], $result['stderr']], [0, '']);
            Assert::true(str_contains($result['stdout'], 'Administrator password changed.'));
            Assert::false(str_contains($result['stdout'] . $result['stderr'], $replacement));
            $after = \Tablo\SqlColumn::string(\Tablo\ServerSchema::rows($fixture->database(), 'SELECT password_hash FROM users')[0] ?? [], 'password_hash');
            Assert::true($after !== $before && password_verify($replacement, $after));
            Assert::same($web->request('/')['status'], 303);
            $login = $web->request('/login');
            Assert::same($web->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => $replacement])['status'], 303);
            Assert::same($web->request('/')['status'], 200);
        } finally { $web->close(); $fixture->close(); }
    }
}
