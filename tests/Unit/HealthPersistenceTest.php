<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\HttpFailure;
use Tablo\Presenter;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HealthPersistenceTest
{
    #[Test]
    public function persistsReasonsAndClearsThemWhenEndpointChanges(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $input = array_replace(UnitFixtures::site(), ['health_path' => '', 'version_path' => '']);
        $id = $sites->save($input);
        $before = $sites->find($id);
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(503)]), new FakeProvider()))->check($before);
        Assert::true($sites->storeCheck($before, $state));
        $stored = $sites->find($id);
        Assert::same($stored['health_error_code'], 'http');
        Assert::same($stored['health_http_status'], 503);
        Assert::same(Presenter::site($stored)['health_reason_label'], 'HTTP 503');
        $sites->save(array_replace($input, ['health_path' => '/up']), $id);
        Assert::same($sites->find($id)['health_error_code'], null);
        Assert::same($sites->find($id)['health_http_status'], null);
        Assert::same($sites->find($id)['checked_at'], null);
        Assert::false($sites->storeCheck($before, $state), 'old empty-endpoint result cannot overwrite new /up settings');
        $after = $sites->find($id);
        $state = (new SiteChecker(new FakeHttp([new HttpFailure('dns')]), new FakeProvider()))->check($after);
        Assert::true($sites->storeCheck($after, $state));
        Assert::same(Presenter::site($sites->find($id))['health_reason_label'], HttpFailure::MESSAGES['dns']);
    }
}
