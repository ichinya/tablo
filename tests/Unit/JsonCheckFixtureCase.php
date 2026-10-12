<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;


use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;

abstract class JsonCheckFixtureCase
{
    protected function check(string $body, array $settings = [], string $version = '{"version":"1.2.3","commit":"abcdef1"}', int $status = 200): array
    {
        $site = array_replace(UnitFixtures::site(), ['health_check_mode' => 'json', 'health_json_path' => '$.value',
            'health_json_operator' => '==', 'health_json_expected_value' => 'ok'], $settings);
        return (new SiteChecker(new FakeHttp([UnitFixtures::response($status, $body),
            UnitFixtures::response(200, $version)]), new FakeProvider()))->check($site);
    }
}
