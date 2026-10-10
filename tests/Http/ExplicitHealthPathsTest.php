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
        $csrf = $this->web->authenticate();
        $input = ['_csrf' => $csrf, 'name' => 'Exact homepage', 'repository' => 'fixture/public', 'branch' => 'main',
            'version_path' => '', 'health_check_mode' => 'json', 'health_json_path' => 'inactive path',
            'health_json_operator' => '>', 'health_json_expected_value' => 'not a number',
            'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        $input['url'] = $this->server->base . '/app/';
        $input['health_path'] = '';
        Assert::same($this->web->request('/sites/new', $input)['status'], 303);
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
    }
}
