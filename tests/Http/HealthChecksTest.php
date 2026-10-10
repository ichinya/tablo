<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Testo\Lifecycle\BeforeTest;
use Testo\Lifecycle\AfterTest;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;

final class HealthChecksTest
{
    private ?WebFixture $web = null;
    private ?TemporaryDirectory $endpoints = null;
    private ?TestServer $server = null;

    #[BeforeTest]
    public function start(): void
    {
        try {
            $this->web = new WebFixture(true);
            $this->endpoints = new TemporaryDirectory('tablo-health-endpoints-');
            $this->server = new TestServer($this->endpoints, dirname(__DIR__) . '/endpoint-router.php', environment: [
                'TABLO_HEALTH_FIXTURE' => $this->endpoints->path, 'TABLO_WORKER_FIXTURE' => $this->endpoints->path]);
        } catch (\Throwable $error) { $this->stop(); throw $error; }
    }

    #[AfterTest]
    public function stop(): void
    {
        $this->web?->close();
        $this->server?->close();
        $this->endpoints?->close();
        $this->web = null;
        $this->server = null;
        $this->endpoints = null;
    }

    #[Test]
    public function savesBlankEndpointAndMatchesManualAndCliResults(): void
    {
        $csrf = $this->web->authenticate();
        $form = $this->web->request('/sites/new');
        Assert::true(preg_match('/id="health_path"[^>]*required/', $form['body']) === 0, 'health field is optional');
        Assert::true(str_contains($form['body'], 'Если оставить пустым, проверяется главная страница. Только HTTP 200 означает Online.'));
        $input = ['_csrf' => $csrf, 'name' => 'Homepage fixture', 'url' => $this->server->base . '/homepage/200',
            'repository' => 'fixture/public', 'branch' => 'main', 'health_path' => '  ', 'version_path' => '',
            'health_check_mode' => 'json', 'health_json_path' => 'inactive path', 'health_json_operator' => '>',
            'health_json_expected_value' => 'not a number', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        Assert::same($this->web->request('/sites/new', $input)['status'], 303, 'server accepts empty endpoint without JavaScript');
        Assert::same($this->web->database()->query('SELECT health_path FROM sites')->fetchColumn(), '');
        $edit = $this->web->request('/sites/1/edit');
        Assert::true(preg_match('/id="health_path"[^>]*value=""/', $edit['body']) === 1, 'empty endpoint round-trips');
        Assert::true(str_contains($edit['body'], 'value="inactive path"'), 'inactive JSON settings retained');
        foreach ([200, 201, 204, 301, 302, 404, 500, 503] as $status) {
            $input['url'] = $this->server->base . '/homepage/' . $status;
            Assert::same($this->web->request('/sites/1/edit', $input)['status'], 303);
            Assert::same($this->web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            $query = 'SELECT online,health_error_code,health_http_status,deployed_version,latest_commit,open_issues,last_error FROM sites WHERE id=1';
            $manual = $this->web->database()->query($query)->fetch();
            Assert::same($manual['online'], $status === 200 ? 1 : 0);
            Assert::same($manual['health_http_status'], $status);
            Assert::same($manual['health_error_code'], $status === 200 ? null : 'http');
            Assert::same($manual['deployed_version'], null);
            Assert::same($manual['open_issues'], 2);
            $root = dirname(__DIR__, 2);
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php',
                $root . '/bin/check.php'], $this->web->directory, [
                    'TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                ]);
            Assert::same($result['exit_code'], $status === 200 ? 0 : 1, $result['stderr']);
            Assert::same($result['stderr'], '');
            Assert::same($this->web->database()->query($query)->fetch(), $manual, 'manual and CLI use one policy');
            $this->check('worker', $csrf, $status === 200 ? 0 : 1);
            Assert::same($this->web->database()->query($query)->fetch(), $manual, 'actual worker shares the received-status policy');
        }
        $dashboard = $this->web->request('/');
        Assert::true(str_contains($dashboard['body'], 'data-health-error="http"') && str_contains($dashboard['body'], 'title="HTTP 503"'), 'stored machine reason and safe explanation displayed');
        $input['health_path'] = '/up';
        Assert::same($this->web->request('/sites/1/edit', $input)['status'], 422, 'JSON settings become active when path is set');
        $input['health_check_mode'] = 'http';
        Assert::same($this->web->request('/sites/1/edit', $input)['status'], 303);
        Assert::same($this->web->database()->query('SELECT health_error_code,health_http_status,checked_at FROM sites')->fetch(),
            ['health_error_code' => null, 'health_http_status' => null, 'checked_at' => null]);
        $uris = $this->uris();
        foreach ([200, 201, 204, 301, 302, 404, 500, 503] as $status) {
            Assert::same(count(array_keys($uris, '/homepage/' . $status, true)), 3);
        }
        Assert::false(in_array('/up', $uris, true), 'received redirects were never followed');
    }

    #[Test]
    public function exactSavedHomepageAndExplicitJoinsReachManualCliAndWorker(): void
    {
        $csrf = $this->web->authenticate();
        $input = ['_csrf' => $csrf, 'name' => 'Exact homepage', 'repository' => 'fixture/public', 'branch' => 'main',
            'version_path' => '', 'health_check_mode' => 'json', 'health_json_path' => 'inactive path',
            'health_json_operator' => '>', 'health_json_expected_value' => 'not a number',
            'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        // Encoded letters are routable on PHP's Windows CLI server too; encoded
        // path separators remain covered by the repository/checker unit matrix.
        foreach (['/app/', '', '/', '/app', '/app///', '/a//b///', '/%61pp/%7e///'] as $index => $path) {
            $input['url'] = '  ' . $this->server->base . $path . '  ';
            // Exercise genuinely missing, whitespace and empty fields through real POST.
            unset($input['health_path']);
            if ($index % 3 !== 0) { $input['health_path'] = $index % 3 === 1 ? '  ' : ''; }
            Assert::same($this->web->request($index === 0 ? '/sites/new' : '/sites/1/edit', $input)['status'], 303);
            foreach ($index % 2 === 0 ? ['manual', 'cli', 'worker'] : ['cli', 'worker', 'manual'] as $mode) {
                $before = count($this->uris());
                $this->check($mode, $csrf, $path === '/app' ? 1 : 0);
                $row = $this->web->database()->query('SELECT * FROM sites WHERE id=1')->fetch();
                Assert::same($row['online'], $path === '/app' ? 0 : 1, $mode . ' must distinguish /app (404) from /app/ (200); saved='
                    . $row['url'] . '; received=' . $row['health_http_status'] . '; raw URI=' . json_encode($this->uris()[$before] ?? null));
                Assert::same($row['health_http_status'], $path === '/app' ? 404 : 200);
                Assert::same($row['url'], $this->server->base . $path);
                Assert::same($row['health_path'], '');
                Assert::same($row['deployed_version'], null);
                Assert::same($this->uris()[$before], $path === '' ? '/' : $path, 'first actual HTTP URI');
                $health = array_filter(array_slice($this->uris(), $before), static fn (string $uri): bool =>
                    !str_starts_with($uri, '/repos/') && !str_starts_with($uri, '/search/'));
                Assert::same(array_values($health), [$path === '' ? '/' : $path], 'blank version causes no HTTP request');
            }
            $edit = $this->web->request('/sites/1/edit')['body'];
            Assert::true(str_contains($edit, 'value="' . $this->server->base . $path . '"'));
            Assert::true(preg_match('/id="health_path"[^>]*value=""/', $edit) === 1);
        }
        foreach (['/', '/app///', '/a//b///'] as $path) {
            foreach (['/up?status=201&encoded=%2F', '/up?status=204', '/json-health?encoded=a%20b'] as $health) {
                $input = array_replace($input, ['url' => $this->server->base . $path, 'health_path' => $health,
                    'version_path' => '/version?format=%7E', 'health_check_mode' => str_starts_with($health, '/json') ? 'json' : 'http',
                    'health_json_path' => '$.result', 'health_json_operator' => '==', 'health_json_expected_value' => 'ok']);
                Assert::same($this->web->request('/sites/1/edit', $input)['status'], 303);
                foreach (['manual', 'cli', 'worker'] as $mode) {
                    $before = count($this->uris());
                    $this->check($mode, $csrf, 0);
                    $row = $this->web->database()->query('SELECT * FROM sites WHERE id=1')->fetch();
                    Assert::same($row['online'], 1);
                    Assert::same($row['health_http_status'], str_contains($health, '201') ? 201 : (str_contains($health, '204') ? 204 : 200));
                    Assert::same($row['deployed_version'], '1.3.1');
                    Assert::same($row['url'], $this->server->base . $path);
                    Assert::same(array_slice($this->uris(), $before, 2), [rtrim($path, '/') . $health, rtrim($path, '/') . $input['version_path']]);
                }
            }
        }
        $input = array_replace($input, ['url' => $this->server->base . '/app/', 'health_path' => '', 'version_path' => '/version?bad=1']);
        Assert::same($this->web->request('/sites/1/edit', $input)['status'], 303);
        foreach (['worker', 'cli', 'manual'] as $mode) {
            $this->check($mode, $csrf, 1);
            $row = $this->web->database()->query('SELECT * FROM sites WHERE id=1')->fetch();
            Assert::same($row['online'], 1);
            Assert::same($row['health_error_code'], null);
            Assert::same($row['deployed_version'], null);
            Assert::true(str_contains($row['last_error'], 'Version:'));
        }
    }

    #[Test]
    public function realInFlightHomepageResultsRefuseRestoredConfigurationInEveryRunner(): void
    {
        $csrf = $this->web->authenticate();
        $sites = new SiteRepository($this->web->database());
        $state = new WorkerStateRepository($this->web->database());
        $child = null;
        try {
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $input = array_replace(SiteRepository::defaults(), ['name' => 'In flight homepage',
                    'url' => $this->server->base . '/barrier/', 'health_path' => '', 'version_path' => '', 'repository' => 'fixture/public']);
                $id = $sites->save($input);
                foreach (['entered', 'release'] as $file) { if (is_file($this->endpoints->path . '/' . $file)) { unlink($this->endpoints->path . '/' . $file); } }
                $root = dirname(__DIR__, 2);
                $command = $mode === 'manual' ? [PHP_BINARY, '-r',
                    '$c=curl_init($argv[1]);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(["_csrf"=>$argv[3]]),CURLOPT_COOKIEFILE=>$argv[2],CURLOPT_PROXY=>"",CURLOPT_TIMEOUT=>10]);curl_exec($c);exit(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===303?0:1);',
                    $this->web->server->base . '/sites/' . $id . '/check', $this->web->directory->path . '/cookies', $csrf]
                    : ($mode === 'cli' ? [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php', $root . '/bin/check.php']
                    : [PHP_BINARY, $root . '/tests/fixtures/worker-process.php']);
                $child = new WorkerProcess($this->endpoints, 'inflight-' . $mode, $command, [
                    'TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_WORKER_BASE' => $this->server->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($this->endpoints->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
                Assert::true(is_file($this->endpoints->path . '/entered'));
                $old = $sites->find($id);
                $sites->save(array_replace($input, ['url' => $this->server->base . '/app', 'health_path' => '/up', 'enabled' => 0]), $id);
                $sites->save($input, $id);
                Assert::true($sites->find($id)['config_revision'] > $old['config_revision']);
                file_put_contents($this->endpoints->path . '/release', 'release');
                if ($mode === 'worker') { $child->awaitOutput('Worker pass complete.'); $state->requestStop($state->generation()); }
                $result = $child->wait();
                Assert::same($result['exit_code'], $mode === 'cli' ? 1 : 0);
                Assert::same($result['stderr'], '');
                Assert::same($sites->find($id)['checked_at'], null, 'ABA response refuses persisted result');
                Assert::same((int) $this->web->database()->query('SELECT count(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0, 'no stale service credit');
                $sites->delete($id);
            }
        } finally {
            file_put_contents($this->endpoints->path . '/release', 'release');
            $child?->close();
            unset($sites, $state, $old);
        }
    }

    private function uris(): array
    {
        $path = $this->endpoints->path . '/uris.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => json_decode($line, true, 4, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES)) : [];
    }

    private function check(string $mode, string $csrf, int $cliExit): void
    {
        if ($mode === 'manual') {
            Assert::same($this->web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            return;
        }
        $root = dirname(__DIR__, 2);
        if ($mode === 'cli') {
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php', $root . '/bin/check.php'],
                $this->endpoints, ['TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1']);
            Assert::same($result['exit_code'], $cliExit);
            Assert::same($result['stderr'], '');
            return;
        }
        $child = new WorkerProcess($this->endpoints, 'exact-worker', [PHP_BINARY, $root . '/tests/fixtures/worker-process.php'],
            ['TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_WORKER_BASE' => $this->server->base]);
        $state = new WorkerStateRepository($this->web->database());
        try {
            $child->awaitOutput('Worker pass complete.');
            $state->requestStop($state->generation());
            $result = $child->wait();
            Assert::same($result['exit_code'], 0);
            Assert::same($result['stderr'], '');
        } finally { $child->close(); }
    }
}
