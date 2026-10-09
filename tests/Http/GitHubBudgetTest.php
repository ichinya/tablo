<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class GitHubBudgetTest
{
    #[Test]
    public function manualAndCliChecksSharePersistentCooldownAndPreservePartialMetrics(): void
    {
        $web = new WebFixture(true);
        $endpoints = new TemporaryDirectory('tablo-budget-http-');
        $server = null;
        try {
            $server = new TestServer($endpoints, dirname(__DIR__) . '/endpoint-router.php');
            $csrf = $web->authenticate();
            $input = ['_csrf' => $csrf, 'name' => 'Synthetic budget', 'url' => $server->base, 'repository' => 'fixture/public',
                'branch' => 'main', 'health_path' => '/up', 'version_path' => '', 'comparison_mode' => 'release',
                'enabled' => '1', 'github_token' => 'rate-fixture-token'];
            Assert::same($web->request('/sites/new', $input)['status'], 303);
            $log = $web->directory->path . '/github-calls.log';
            file_put_contents($log, '');
            Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            $query = 'SELECT online,latest_release,latest_commit,open_issues,open_prs,last_error FROM sites WHERE id=1';
            $manual = $web->database()->query($query)->fetch();
            Assert::same($manual['online'], 1);
            Assert::same($manual['latest_release'], 'v1.0.0');
            Assert::same($manual['latest_commit'], str_repeat('a', 40));
            Assert::same($manual['open_issues'], null);
            Assert::same($manual['open_prs'], null);
            Assert::false(str_contains($manual['last_error'], 'rate-fixture-token'));
            Assert::same(count(file($log)), 3, 'release, branch, issue; PR deferred');
            Assert::same($web->database()->query('SELECT scope,resource FROM github_cooldowns')->fetchAll(),
                [['scope' => 'site:1', 'resource' => 'search']]);
            file_put_contents($log, '');
            Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            Assert::same(count(file($log)), 2, 'fresh web request respects stored search cooldown');
            $root = dirname(__DIR__, 2);
            file_put_contents($log, '');
            $environment = ['TABLO_DB' => $web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                'TABLO_TEST_GITHUB_LOG' => $log];
            for ($run = 0; $run < 2; ++$run) {
                $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php',
                    $root . '/bin/check.php'], $web->directory, $environment);
                Assert::same($result['exit_code'], 1);
                Assert::same($result['stderr'], '');
                Assert::true(str_contains($result['stdout'], '1: attention'));
                Assert::same($web->database()->query($query)->fetch(), $manual);
            }
            Assert::same(count(file($log)), 4, 'two fresh CLI processes send only core requests');
            Assert::false(str_contains($web->request('/')['body'], 'rate-fixture-token'));
        } finally {
            $server?->close();
            $web->close();
            $endpoints->close();
        }
    }
}
