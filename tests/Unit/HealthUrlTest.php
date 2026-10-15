<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class HealthUrlTest
{
    #[Test]
    public function retainsValidatedUrlBytesAndJoinsOnlyExplicitPaths(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        foreach (['http://example.test', 'https://example.test/', 'https://example.test/app/',
            'https://example.test/app///', 'https://example.test/a//b///', 'https://example.test/%2Fapp/%7e///'] as $url) {
            $input = array_replace(UnitFixtures::site(), ['url' => '  ' . $url . '  ', 'health_path' => '', 'version_path' => '']);
            $id = $sites->save($input);
            Assert::same($sites->find($id)['url'], $url);
            $http = new FakeHttp([UnitFixtures::response(200, '<html>ok</html>')]);
            Assert::same((new SiteChecker($http, new FakeProvider()))->check($sites->find($id))['online'], 1);
            Assert::same($http->requests, [[$url, []]]);
            $input['health_path'] = '/up?check=%2F&value=a%20b';
            $input['version_path'] = '/version?format=%7E';
            $sites->save($input, $id);
            $http = new FakeHttp([UnitFixtures::response(204), UnitFixtures::response(200, '{"version":"v1"}')]);
            $state = (new SiteChecker($http, new FakeProvider()))->check($sites->find($id));
            Assert::same($sites->find($id)['url'], $url);
            Assert::same($http->requests, [[rtrim($url, '/') . $input['health_path'], []], [rtrim($url, '/') . $input['version_path'], []]]);
            Assert::same($state['online'], 1);
            Assert::same($state['deployed_version'], 'v1');
        }
        foreach (['https://example.test/?q=%2F', 'https://example.test/#part', 'https://name:pass@example.test/'] as $url) {
            UnitFixtures::rejects(fn () => SiteRepository::normalize(array_replace(UnitFixtures::site(), ['url' => $url])), 'base URL validation retained');
        }
    }
}
