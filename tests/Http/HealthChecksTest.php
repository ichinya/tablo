<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\Subprocess;

use Tablo\Tests\Support\HealthCheckFixture;
use Tablo\Tests\Support\HealthCheckDiagnostics;

final class HealthChecksTest
{
    use HealthCheckFixture;

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
            $diagnostic = HealthCheckDiagnostics::capture($result, $this->web->database());
            Assert::same($result['exit_code'], $status === 200 ? 0 : 1, $diagnostic);
            Assert::same(strlen($result['stderr']), 0, $diagnostic);
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
}
