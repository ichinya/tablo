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
        $csrf = $this->web->authenticate();
        $input = ['_csrf' => $csrf, 'name' => 'Exact homepage', 'repository' => 'fixture/public', 'branch' => 'main',
            'version_path' => '', 'health_check_mode' => 'json', 'health_json_path' => 'inactive path',
            'health_json_operator' => '>', 'health_json_expected_value' => 'not a number',
            'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        // Use uppercase escapes for the hosted fixture; supplied lowercase bytes
        // remain covered by the unit matrix.
        foreach (['/app/', '', '/', '/app', '/app///', '/a//b///', '/%2Fapp/%7E///'] as $index => $path) {
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
    }
}
