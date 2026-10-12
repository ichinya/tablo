<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;


/**
 * @phpstan-import-type Record from \Tablo\SqlSitesBranchCheckedAtComparisonModeRow as Site
 */
final class HomepageObservationAssertions
{
    /**
     * @param Site $row
     */
    public static function row(array $row, string $path, string $mode, string $base, ?string $uri): void
    {
                Assert::same($row['online'], $path === '/app' ? 0 : 1, $mode . ' must distinguish /app (404) from /app/ (200); saved='
                    . $row['url'] . '; received=' . (string) $row['health_http_status'] . '; raw URI=' . \Tablo\Tests\Support\FixtureValue::string(json_encode($uri)));
                Assert::same($row['health_http_status'], $path === '/app' ? 404 : 200);
                Assert::same($row['url'], $base . $path);
                Assert::same($row['health_path'], '');
                Assert::same($row['deployed_version'], null);
                Assert::same($uri, $path === '' ? '/' : $path, 'first actual HTTP URI');

    }
}
