<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class IncidentsTest
{
    #[Test]
    public function authenticatedVoltEscapesNamesPreservesSecurityAndReadsOnlyFiniteProjection(): void
    {
        $web = new WebFixture();
        try {
            $web->authenticate();
            Assert::same($web->request('/incidents', cookie: false)['status'], 303);
            $db = $web->database();
            $sites = new SiteRepository($db);
            $id = $sites->save(array_replace(F::site(), ['name' => '<img src=x onerror=alert(1)>']));
            F::accept($sites, $id, 0, '2026-01-01T00:00:00Z', ['health_error_code' => 'http', 'health_http_status' => 503,
                'last_error' => 'synthetic-private-error', 'deployed_version' => 'synthetic-private-version']);
            $before = F::rows($db);
            foreach (['incidents', 'incident_checkpoints', 'check_history', 'sites'] as $table) {
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                    $db->exec('CREATE TRIGGER guard_' . $table . '_' . $operation . ' BEFORE ' . $operation . ' ON ' . $table
                        . " BEGIN SELECT RAISE(ABORT,'read-mutation-refusal'); END");
                }
            }
            $response = $web->request('/incidents');
            Assert::same($response['status'], 200);
            Assert::true(str_contains($response['body'], '&lt;img src=x onerror=alert(1)&gt;'));
            Assert::false(str_contains($response['body'], '<img src=x'));
            Assert::true(str_contains($response['body'], 'Первое наблюдение Offline · UTC'));
            Assert::true(str_contains($response['body'], 'HTTP 503'));
            Assert::true(str_contains($response['body'], 'Не наблюдалось'));
            Assert::true(str_contains($response['body'], 'Нет новых наблюдений'));
            foreach (['synthetic-private-error', 'synthetic-private-version', 'https://example.com', 'example/service', 'fixture-token'] as $marker) {
                Assert::false(str_contains($response['body'], $marker), $marker);
            }
            foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Cache-Control: no-store', "frame-ancestors 'none'"] as $header) {
                Assert::true(str_contains($response['headers'], $header));
            }
            Assert::same(F::rows($db), $before);
            Assert::false(is_file($web->directory->path . '/github-calls.log'), 'GET has zero upstream requests');
            Assert::false(is_file($web->directory->path . '/github-token.key'), 'read has no credential work');
            Assert::true(str_contains($response['body'], 'href="/incidents"'));
            foreach (glob($web->directory->path . '/runtime/sessions/sess_*') as $session) {
                $contents = file_get_contents($session);
                file_put_contents($session, preg_replace('/authenticated_at\|i:[0-9]+;/', 'authenticated_at|i:1;', $contents));
            }
            $expired = $web->request('/incidents');
            Assert::same($expired['status'], 303);
            Assert::true(str_contains($expired['headers'], 'Location: /login'));
        } finally { unset($before, $sites, $db); $web->close(); }
    }

    #[Test]
    public function actualRouteBoundsMissingSiteScopedCursorAndCeilingAreStrict(): void
    {
        $web = new WebFixture();
        try {
            $web->authenticate();
            $db = $web->database();
            $sites = new SiteRepository($db);
            $id = $sites->save(F::site());
            $other = $sites->save(F::site());
            for ($i = 0; $i < 52; $i++) {
                F::accept($sites, $id, 0, '2026-01-01T00:00:00Z');
                F::accept($sites, $id, 1, '2026-01-01T00:00:01Z');
            }
            $first = $web->request('/incidents');
            Assert::same(substr_count($first['body'], 'data-incident-id='), 50);
            preg_match('/href="([^\"]+)"[^>]*>Следующая страница/', $first['body'], $match);
            Assert::true(isset($match[1]));
            F::accept($sites, $id, 0, '2025-01-01T00:00:00Z');
            $second = $web->request(html_entity_decode($match[1]));
            Assert::same($second['status'], 200);
            Assert::same(substr_count($second['body'], 'data-incident-id='), 2);
            Assert::false(str_contains($second['body'], 'data-incident-id="105"'));
            foreach (['limit=0', 'limit=101', 'limit=01', 'limit=1e2', 'limit[]=1', 'site_id[]=1', 'site_id=0',
                'site_id=01', 'site_id=9223372036854775808', 'cursor[]=a', 'cursor=', 'cursor=' . str_repeat('a', 257)] as $query) {
                Assert::same($web->request('/incidents?' . $query)['status'], 422, $query);
            }
            Assert::same($web->request('/incidents?site_id=999')['status'], 404);
            $filtered = $web->request('/incidents?site_id=' . $id . '&limit=1');
            preg_match('/href="([^\"]+)"[^>]*>Следующая страница/', $filtered['body'], $match);
            $url = html_entity_decode($match[1]);
            Assert::same($web->request(str_replace('site_id=' . $id, 'site_id=' . $other, $url))['status'], 422);
            $sites->delete($id);
            Assert::same($web->request($url)['status'], 404, 'deleted filter never falls back to global');
            Assert::same($web->request('/incidents', ['_csrf' => WebFixture::csrf($first)])['status'], 404, 'GET-only');
        } finally { unset($sites, $db); $web->close(); }
    }
}
