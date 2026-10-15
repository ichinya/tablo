<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\Subprocess;

use Tablo\Tests\Support\HealthCheckFixture;

final class HealthChecksTest
{
    use HealthCheckFixture;

    #[Test]
    public function savesBlankEndpointAndMatchesManualAndCliResults(): void
    {
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $server = \Tablo\Tests\Support\FixtureValue::object($this->server);
        $csrf = $web->authenticate();
        $input = (new HealthInitialForm($web))->input($server->base, $csrf);
        foreach ([200, 201, 204, 301, 302, 404, 500, 503] as $status) {
            $input['url'] = $server->base . '/homepage/' . $status;
            Assert::same($web->request('/sites/1/edit', $input)['status'], 303);
            Assert::same($web->request('/sites/1/check', ['_csrf' => $csrf])['status'], 303);
            $historyBeforeCli = (int) \Tablo\SqlStatement::checked($web->database()->query('SELECT COUNT(*) FROM check_history WHERE site_id=1'))->fetchColumn();
            Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($web->database()->query('SELECT online,health_error_code,health_http_status,version_status FROM check_history ORDER BY id DESC LIMIT 1'))->fetch(), \Tablo\SqlCheckHistoryHealthErrorCodeHealthHttpStatusOnlineRow::checked(...)),
                ['online' => $status === 200 ? 1 : 0, 'health_error_code' => $status === 200 ? null : 'http', 'health_http_status' => $status, 'version_status' => 'skipped']);
            $query = 'SELECT online,health_error_code,health_http_status,deployed_version,latest_commit,open_issues,last_error FROM sites WHERE id=1';
            $manual = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($web->database()->query($query))->fetch(), \Tablo\SqlSitesDeployedVersionHealthErrorCodeHealthHttpStatusRow::checked(...)));
            HomepageStatusAssertions::manual($manual, $status);
            $root = dirname(__DIR__, levels: 2);
            $result = Subprocess::run([PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/FixtureCliGitHubConnection.php',
                $root . '/bin/check.php'], $web->directory, [
                    'TABLO_DB' => \Tablo\Tests\Support\FixtureValue::object($web->directory)->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1',
                ]);
            (new HomepageStatusAssertions($web))->cli($result, $status, $historyBeforeCli, $manual);
            $this->check('worker', $csrf, $status === 200 ? 0 : 1);
            Assert::same(\Tablo\SqlStatement::checked($web->database()->query($query))->fetch(), $manual, 'actual worker shares the received-status policy');
        }
        (new HealthFormAssertions($web))->endpointChange($input);
        $uris = $this->uris();
        foreach ([200, 201, 204, 301, 302, 404, 500, 503] as $status) {
            Assert::same(count(array_keys($uris, '/homepage/' . $status, true)), 3);
        }
        Assert::false(in_array('/up', $uris, strict: true), 'received redirects were never followed');
    }
}
