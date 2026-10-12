<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteChecker;
use Testo\Assert;

final class EndpointStatusAssertions
{
    public static function matrix(SiteChecker $checker, array $site, string $base): void
    {
            foreach ([200, 201, 204, 301, 302, 401, 403, 404, 500] as $status) {
                $home = $checker->check([...$site, 'url' => $base . '/home/' . $status . '///', 'health_path' => '']);
                Assert::same($home['online'], (int) ($status === 200));
                Assert::same($home['health_http_status'], $status);
                $explicit = $checker->check([...$site, 'health_path' => '/up?status=' . $status]);
                $final = in_array($status, [301, 302], strict: true) ? 200 : $status;
                Assert::same($explicit['online'], (int) ($final >= 200 && $final < 300));
                Assert::same($explicit['health_http_status'], $final);
            }

    }
}
