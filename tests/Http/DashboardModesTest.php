<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\DashboardModesFixture;
use Testo\Assert;
use Testo\Test;

/** @phpstan-import-type View from \Tablo\Presenter */
final class DashboardModesTest
{
    #[Test]
    public function bothViewsExcludeSeededEncryptedCredentialsAndEndpointQueries(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $database = $fixture->web->database();
            $vault = new \Tablo\TokenVault($fixture->directory->path . '/key', external: true);
            $repository = new \Tablo\SiteRepository($database, $vault);
            $git = \Tablo\Tests\Support\FixtureCredentials::value(label: 'dashboard-git-marker');
            $endpoint = \Tablo\Tests\Support\FixtureCredentials::value(label: 'dashboard-endpoint-marker');
            $id = $repository->save(array_replace(\Tablo\SiteInput::defaults(), ['name' => 'Synthetic credential example',
                'url' => 'https://credentials.example.com', 'repository' => 'example/credentials',
                'github_token' => $git, 'endpoint_token' => $endpoint, 'health_path' => '/up?private_path_marker=1']));
            $stored = \Tablo\Tests\Support\FixtureValue::row($repository->find($id));
            $response = $fixture->web->request('/');
            Assert::same($response['status'], 200);
            foreach ([$git, $endpoint, 'private_path_marker', \Tablo\SqlColumn::string($stored, 'github_token'), \Tablo\SqlColumn::string($stored, 'endpoint_token')] as $marker) {
                Assert::true(!str_contains($response['body'], $marker), 'credentials and ciphertext excluded from both views/attributes');
            }
        });
    }

    #[Test]
    public function preservesOrderedPresenterFieldsAndSemanticTable(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $ids = DashboardModesFixture::seed($fixture->web->database());
            $response = $fixture->web->request('/');
            Assert::same($response['status'], 200);
            $html = $response['body'];
            $expected = [\Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 1)), \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 2)), \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 0)), ...array_slice($ids, offset: 3)];
            Assert::same(DashboardModesFixture::ids($html, 'tile'), $expected, 'sort_order then id, independent of insertion order');
            Assert::same(DashboardModesFixture::ids($html, 'table'), $expected, 'table preserves the exact logical order');
            $documentIds = array_map(static fn (\DOMElement $node): string => $node->getAttribute('id'), DashboardModesFixture::elements($html, '//*[@id]'));
            Assert::same(count($documentIds), count(array_unique($documentIds)), 'no duplicate document ids');
            Assert::same(count(DashboardModesFixture::elements($html, '//table/caption')), 1);
            Assert::same(count(DashboardModesFixture::elements($html, '//thead/tr/th[@scope="col"]')), 10);
            Assert::same(count(DashboardModesFixture::elements($html, '//tbody/tr/th[@scope="row"]')), count($ids));
            foreach (array_map(\Tablo\Presenter::site(...), (new \Tablo\SiteRepository($fixture->web->database()))->all()) as $site) {
                foreach (['tile', 'table'] as $view) {
                    $nodes = DashboardModesFixture::elements($html, '//*[@data-dashboard-view="' . $view . '"]//*[@data-site-id="' . \Tablo\SqlColumn::integer($site, 'id') . '"]');
                    Assert::same(count($nodes), 1);
                    $text = \Tablo\Tests\Support\FixtureValue::object($nodes[0] ?? null)->textContent;
                    foreach (['name', 'repository', 'branch', 'status', 'deployed_label', 'latest_label', 'comparison', 'checked_label'] as $field) {
                        Assert::true(str_contains($text, \Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($site, $field))), $view . ' preserves ' . $field);
                    }
                }
            }

        });
    }

    #[Test]
    public function preservesUnknownOptionalPausedAndEscapedPartialStates(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $ids = DashboardModesFixture::seed($fixture->web->database());
            $html = $fixture->web->request('/')['body'];
            $zero = DashboardModesFixture::elements($html, '//tbody/tr[@data-site-id="' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 2)) . '"]/td');
            Assert::same(array_map(static fn (\DOMElement $node): string => trim($node->textContent), array_slice($zero, offset: 4, length: 3)), ['0', '0', '0']);
            $unknown = DashboardModesFixture::elements($html, '//tbody/tr[@data-site-id="' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 6)) . '"]/td');
            Assert::same(array_map(static fn (\DOMElement $node): string => trim($node->textContent), array_slice($unknown, offset: 4, length: 3)), ['—', '—', '—']);
            Assert::true(str_contains($html, '&lt;script&gt;window.dashboardUnsafe=1&lt;/script&gt;'));
            Assert::same(count(DashboardModesFixture::elements($html, '//script[not(@src)]')), 0, 'hostile provider text stays inert');
            Assert::true(!str_contains($html, 'fixture_private_query_marker'), 'private endpoint query never rendered');
            $optional = \Tablo\Tests\Support\FixtureValue::object(DashboardModesFixture::elements($html, '//tbody/tr[@data-site-id="' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 10)) . '"]')[0] ?? null);
            Assert::same($optional->getAttribute('data-attention'), '0', 'healthy optional-version row has no false warning');
            Assert::true(str_contains($optional->textContent, 'Версия сайта не отслеживается'));
            $paused = \Tablo\Tests\Support\FixtureValue::object(DashboardModesFixture::elements($html, '//tbody/tr[@data-site-id="' . \Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($ids, 4)) . '"]')[0] ?? null);
            Assert::same($paused->getAttribute('data-attention'), '0');
            Assert::true(!str_contains($paused->textContent, 'Устарело'), 'pause suppresses stale warning');
        });
    }

    #[Test]
    public function lockedRenderTreatsOmittedMetricsAsUnknownAndEscapesAttributes(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            DashboardModesFixture::seed($fixture->web->database(), set: 'demo');
            $sites = array_map(\Tablo\Presenter::site(...), (new \Tablo\SiteRepository($fixture->web->database()))->all());
            $first = \Tablo\Tests\Support\FixtureValue::row($sites[0] ?? null);
            unset($first['open_issues'], $first['open_prs'], $first['response_time_ms']);
            $first['name'] = 'Unicode Ж " <img src=x onerror=alert(1)> &';
            $sites[0] = $first;
            $runtime = $fixture->directory->path . '/render';
            mkdir($runtime); mkdir($runtime . '/views');
            $response = (new \Tablo\PageResponse($runtime))->render('dashboard', ['title' => 'Dashboard', 'sites' => $sites,
                'stats' => ['total' => 5, 'online' => 3, 'attention' => 2, 'paused' => 1], 'authenticated' => true, 'csrf' => $fixture->csrf]);
            $html = \Tablo\Tests\Support\FixtureValue::string($response->getContent());
            Assert::same(count(DashboardModesFixture::elements($html, '//img')), 0);
            foreach (['tile', 'table'] as $view) {
                $node = \Tablo\Tests\Support\FixtureValue::object(DashboardModesFixture::elements($html, '//*[@data-dashboard-view="' . $view . '"]//*[@data-site-id="' . \Tablo\SqlColumn::integer($sites[0], 'id') . '"]')[0] ?? null);
                Assert::true(str_contains($node->getAttribute('data-search'), $sites[0]['name']));
                Assert::true(substr_count($node->textContent, needle: '—') >= 3, 'omitted metrics retain unknown dashes');
            }
        });
    }
}
