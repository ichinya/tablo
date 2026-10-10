<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;

use Tablo\Tests\Support\HealthCheckFixture;

final class HealthPartialResultsTest
{
    use HealthCheckFixture;

    #[Test]
    public function successfulHomepageSurvivesVersionFailureInEveryRunner(): void
    {
        $csrf = $this->web->authenticate();
        $input = ['_csrf' => $csrf, 'name' => 'Exact homepage', 'repository' => 'fixture/public', 'branch' => 'main',
            'version_path' => '', 'health_check_mode' => 'json', 'health_json_path' => 'inactive path',
            'health_json_operator' => '>', 'health_json_expected_value' => 'not a number',
            'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        $input['url'] = $this->server->base . '/app/';
        $input['health_path'] = '';
        Assert::same($this->web->request('/sites/new', $input)['status'], 303);
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
}
