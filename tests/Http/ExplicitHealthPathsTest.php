<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;

use Tablo\Tests\Support\HealthCheckFixture;

final class ExplicitHealthPathsTest
{
    use HealthCheckFixture;

    #[Test]
    public function explicitJoinsReachManualCliAndWorker(): void
    {
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $server = \Tablo\Tests\Support\FixtureValue::object($this->server);
        $csrf = $web->authenticate();
        $input = ['_csrf' => $csrf, 'name' => 'Exact homepage', 'repository' => 'fixture/public', 'branch' => 'main',
            'version_path' => '', 'health_check_mode' => 'json', 'health_json_path' => 'inactive path',
            'health_json_operator' => '>', 'health_json_expected_value' => 'not a number',
            'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        $input['url'] = $server->base . '/app/';
        $input['health_path'] = '';
        Assert::same($web->request('/sites/new', $input)['status'], 303);
        foreach (['/', '/app///', '/a//b///'] as $path) {
            foreach (['/up?status=201&encoded=%2F', '/up?status=204', '/json-health?encoded=a%20b'] as $health) {
                $input = array_replace($input, ['url' => $server->base . $path, 'health_path' => $health,
                    'version_path' => '/version?format=%7E', 'health_check_mode' => str_starts_with($health, '/json') ? 'json' : 'http',
                    'health_json_path' => '$.result', 'health_json_operator' => '==', 'health_json_expected_value' => 'ok']);
                Assert::same($web->request('/sites/1/edit', $input)['status'], 303);
                foreach (['manual', 'cli', 'worker'] as $mode) {
                    $before = count($this->uris());
                    $this->check($mode, $csrf, 0);
                    $row = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($web->database()->query('SELECT * FROM sites WHERE id=1'))->fetch(), \Tablo\SqlSitesBranchCheckedAtComparisonModeRow::checked(...)));
                    Assert::same($row['online'], 1);
                    Assert::same($row['health_http_status'], str_contains($health, '201') ? 201 : (match (true) { (str_contains($health, '204')) => 204, default => 200 }));
                    Assert::same($row['deployed_version'], '1.3.1');
                    Assert::same($row['url'], $server->base . $path);
                    Assert::same(array_slice($this->uris(), $before, length: 2), [rtrim($path, characters: '/') . $health, rtrim($path, characters: '/') . \Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($input, 'version_path'))]);
                }
            }
        }
    }
}
