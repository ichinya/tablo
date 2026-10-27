<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\DashboardStatePage;
use Tablo\JsonRecord;
use Tablo\Presenter;
use Tablo\SiteRepository;
use Testo\Assert;

final class DashboardStateAssertions
{
    /** @param array{status: int, headers: string, body: string} $response */
    public static function success(array $response, string $csrf): array
    {
        Assert::same($response['status'], 200);
        Assert::true(str_contains(strtolower($response['headers']), 'content-type: application/json'));
        Assert::true(str_contains(strtolower($response['headers']), 'cache-control: no-store, private'));
        Assert::false(str_contains($response['headers'], 'Location:'));
        Assert::true(strlen($response['body']) <= DashboardStatePage::MAXIMUM_JSON_BYTES);
        $state = JsonRecord::decode($response['body']);
        Assert::same(array_keys($state), ['dashboard_version', 'csrf', 'stats', 'html']);
        Assert::same($state['dashboard_version'] ?? null, 1);
        Assert::same($state['csrf'] ?? null, $csrf);
        Assert::same(array_keys(JsonRecord::checked($state['stats'] ?? null)), ['total', 'online', 'attention', 'paused']);
        return $state;
    }

    public static function presentedSites(string $html, SiteRepository $repository): void
    {
        foreach (array_map(Presenter::site(...), $repository->all()) as $site) {
            $node = FixtureValue::object(DashboardModesFixture::elements($html,
                '//*[@data-dashboard-view="table"]//*[@data-site-id="' . \Tablo\SqlColumn::integer($site, 'id') . '"]')[0] ?? null);
            foreach (['status', 'comparison', 'checked_label', 'deployed_label', 'latest_label'] as $field) {
                Assert::true(str_contains($node->textContent, FixtureValue::string($site[$field] ?? null)));
            }
        }
    }
}
