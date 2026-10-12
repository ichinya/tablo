<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;

use Tablo\Tests\Support\HealthCheckFixture;

final class HomepageUrlTest
{
    use HealthCheckFixture;

    #[Test]
    public function exactSavedHomepageReachesManualCliAndWorker(): void
    {
        $web = \Tablo\Tests\Support\FixtureValue::object($this->web);
        $server = \Tablo\Tests\Support\FixtureValue::object($this->server);
        $csrf = $web->authenticate();
        $input = ['_csrf' => $csrf, 'name' => 'Exact homepage', 'repository' => 'fixture/public', 'branch' => 'main',
            'version_path' => '', 'health_check_mode' => 'json', 'health_json_path' => 'inactive path',
            'health_json_operator' => '>', 'health_json_expected_value' => 'not a number',
            'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        // Use uppercase escapes for the hosted fixture; supplied lowercase bytes
        // remain covered by the unit matrix.
        foreach (['/app/', '', '/', '/app', '/app///', '/a//b///', '/%2Fapp/%7E///'] as $index => $path) {
            $input['url'] = '  ' . $server->base . $path . '  ';
            // Exercise genuinely missing, whitespace and empty fields through real POST.
            unset($input['health_path']);
            if ($index % 3 !== 0) { $input['health_path'] = $index % 3 === 1 ? '  ' : ''; }
            Assert::same($web->request($index === 0 ? '/sites/new' : '/sites/1/edit', $input)['status'], 303);
            foreach ($index % 2 === 0 ? ['manual', 'cli', 'worker'] : ['cli', 'worker', 'manual'] as $mode) {
                $before = count($this->uris());
                $this->check($mode, $csrf, $path === '/app' ? 1 : 0);
                $row = \Tablo\Tests\Support\FixtureValue::fetched(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($web->database()->query('SELECT * FROM sites WHERE id=1'))->fetch(), \Tablo\SqlSitesBranchCheckedAtComparisonModeRow::checked(...)));
                HomepageObservationAssertions::row($row, $path, $mode, $server->base, $this->uris()[$before] ?? null);
                $health = array_filter(array_slice($this->uris(), $before), static fn (string $uri): bool =>
                    !str_starts_with($uri, '/repos/') && !str_starts_with($uri, '/search/'));
                Assert::same(array_values($health), [$path === '' ? '/' : $path], 'blank version causes no HTTP request');
            }
            $edit = $web->request('/sites/1/edit')['body'];
            Assert::true(str_contains($edit, 'value="' . $server->base . $path . '"'));
            Assert::true(preg_match('/id="health_path"[^>]*value=""/', $edit) === 1);
        }
    }
}
