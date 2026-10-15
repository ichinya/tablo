<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Testo\Assert;

/**
 * @phpstan-import-type SiteRecord from \Tablo\SiteRow
 */
final class WorkerDashboardAssertions
{
    public function __construct(private readonly WebFixture $web) {}

    /**
     * @param SiteRecord $workerRow
     */
    public function visible(array $workerRow): void
    {
            Assert::same($workerRow['online'], 1);
            Assert::true($workerRow['checked_at'] !== null);
            $page = $this->web->request('/');
            Assert::same($page['status'], 200);
            Assert::true(str_contains($page['body'], 'Worker visible fixture') && str_contains($page['body'], 'Online'));
            Assert::true(str_contains($page['body'], gmdate('d.m.Y H:i', \Tablo\Tests\Support\FixtureValue::integer(strtotime(\Tablo\Tests\Support\FixtureValue::string($workerRow['checked_at'])))) . ' UTC'));

    }
    /**
     * @param SiteRecord $manual
     * @param SiteRecord $oneShot
     * @param SiteRecord $workerRow
     */
    public static function equivalent(array $manual, array $oneShot, array $workerRow): void
    {
            foreach (['online','deployed_version','deployed_commit','latest_release','latest_commit','open_issues','open_prs','last_error'] as $field) {
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($manual, $field), \Tablo\Tests\Support\FixtureValue::at($oneShot, $field), 'manual/actual bin check equality');
            }
            Assert::same($oneShot['online'], $workerRow['online']);
            Assert::same($oneShot['open_issues'], $workerRow['open_issues']);
            Assert::false(array_key_exists('worker_service', $oneShot));

    }
}
