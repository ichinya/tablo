<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Testo\Lifecycle\BeforeTest;
use Testo\Lifecycle\AfterTest;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;

final class JsonChecksTest
{
    private ?WebFixture $web = null;
    private ?TemporaryDirectory $endpoints = null;
    private ?TestServer $server = null;

    #[BeforeTest]
    public function start(): void
    {
        try {
            $this->web = new WebFixture(true);
            $this->endpoints = new TemporaryDirectory('tablo-json-endpoints-');
            $this->server = new TestServer($this->endpoints, dirname(__DIR__) . '/endpoint-router.php');
        } catch (\Throwable $error) {
            $this->stop();
            throw $error;
        }
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

    private function input(string $csrf): array
    {
        return ['_csrf' => $csrf, 'name' => 'JSON fixture', 'url' => $this->server->base,
            'repository' => 'fixture/public', 'branch' => 'main', 'health_path' => '/json-health',
            'health_check_mode' => 'json', 'health_json_path' => '$.result', 'health_json_operator' => '==',
            'health_json_expected_value' => 'ok', 'version_path' => '/json-version',
            'version_json_path' => '$.build.version', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
    }

    #[Test]
    public function roundTripsJsonFieldsAndValidatesWithoutJavascript(): void
    {
        $csrf = $this->web->authenticate();
        $form = $this->web->request('/sites/new');
        Assert::same($form['status'], 200);
        Assert::true(str_contains($form['body'], 'value="http" selected'), 'default mode');
        preg_match('/id="health_json_operator"[^>]*>(.*?)<\/select>/s', $form['body'], $select);
        preg_match_all('/<option value="([^"]*)"/', $select[1], $options);
        Assert::same(array_map(fn ($value) => html_entity_decode($value, ENT_QUOTES, 'UTF-8'), $options[1]), ['>', '>=', '<', '<=', '!=', '==', 'contains']);
        $input = $this->input($csrf);
        $input['health_json_operator'] = 'contains';
        $input['health_json_expected_value'] = '<script>alert("fixture")</script>';
        Assert::same($this->web->request('/sites/new', $input)['status'], 303);
        $edit = $this->web->request('/sites/1/edit');
        Assert::true(str_contains($edit['body'], 'value="$.build.version"') && str_contains($edit['body'], 'value="json" selected'), 'configuration rendered');
        Assert::true(str_contains($edit['body'], '&lt;script&gt;') && !str_contains($edit['body'], '<script>alert("fixture")'), 'expected value escaped');
        Assert::same($this->web->database()->query('SELECT health_json_expected_value FROM sites')->fetchColumn(), $input['health_json_expected_value']);
        $badPath = $this->web->request('/sites/1/edit', array_replace($input, ['health_json_path' => '$.bad(']));
        Assert::same($badPath['status'], 422);
        Assert::true(str_contains($badPath['body'], 'id="health_json_path-error"') && str_contains($badPath['body'], 'value="$.bad("') && str_contains($badPath['body'], 'aria-invalid="true"'), 'path error and input retained');
        $badNumber = $this->web->request('/sites/1/edit', array_replace($input, ['health_json_operator' => '>', 'health_json_expected_value' => 'no']));
        Assert::same($badNumber['status'], 422);
        Assert::true(str_contains($badNumber['body'], 'id="health_json_expected_value-error"') && str_contains($badNumber['body'], 'value="no"'), 'numeric error bound to expected value');
        $disabled = array_replace($this->input($csrf), ['version_path' => '', 'version_json_path' => 'not a path']);
        Assert::same($this->web->request('/sites/1/edit', $disabled)['status'], 303, 'disabled selector ignored server-side');
        Assert::same($this->web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
        $state = $this->web->database()->query('SELECT online, deployed_version, last_error FROM sites')->fetch();
        Assert::same($state, ['online' => 1, 'deployed_version' => null, 'last_error' => null]);
    }

    #[Test]
    public function actualCliAndManualChecksStoreEquivalentJsonOutcomes(): void
    {
        $csrf = $this->web->authenticate();
        $input = $this->input($csrf);
        Assert::same($this->web->request('/sites/new', $input)['status'], 303);
        foreach ([
            [[], 1, 0],
            [['health_json_expected_value' => 'error'], 0, 1],
            [['health_json_path' => '$.missing'], null, 1],
        ] as [$settings, $online, $exit]) {
            Assert::same($this->web->request('/sites/1/edit', array_replace($input, $settings))['status'], 303);
            Assert::same($this->web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            $query = 'SELECT online,deployed_version,deployed_commit,latest_release,latest_commit,open_issues,open_prs,last_error FROM sites WHERE id=1';
            $manual = $this->web->database()->query($query)->fetch();
            Assert::same($manual['online'], $online);
            Assert::same($manual['deployed_version'], '1.2.3');
            $root = dirname(__DIR__, 2);
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php',
                $root . '/bin/check.php'], $this->web->directory, [
                    'TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                ]);
            Assert::same($result['exit_code'], $exit, 'native CLI: ' . $result['stderr']);
            Assert::same($result['stderr'], '', 'CLI is quiet on stderr');
            Assert::true(str_contains($result['stdout'], $exit === 0 ? '1: ok' : '1: attention'));
            Assert::same($this->web->database()->query($query)->fetch(), $manual, 'same checker in actual CLI and HTTP');
        }
    }
}
