<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteChecker;
use Tablo\Tests\Support\EndpointFixture;
use Testo\Assert;

final class EndpointFailureAssertions
{

    public static function independent(SiteChecker $checker, array $site, string $base): void
    {
            $large = $checker->check([...$site, 'url' => $base, 'health_path' => '/large']);
            Assert::same($large['online'], null);
            Assert::same($large['health_error_code'], 'size');
            $badVersion = $checker->check([...$site, 'health_path' => '/up', 'version_path' => '/version?bad=1']);
            Assert::same($badVersion['online'], 1);
            Assert::same($badVersion['latest_release'], 'v1.3.2');
            Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string($badVersion['last_error']), EndpointFixture::tokenA()));

    }
}
