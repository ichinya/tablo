<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Presenter;
use Tablo\SiteChecker;
use Tablo\SiteInput;

use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;

final class HealthFailureEvidenceTest
{


    #[Test]
    public function distinguishesOfflineFromTransportFailures(): void
    {
        $sample = UnitFixtures::site();
        $site = SiteInput::normalize($sample);
        $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(503), UnitFixtures::response(200, '{"commit":"abc"}')]), new FakeProvider()))->check($site);
        Assert::true($state['online'] === 0 && $state['deployed_commit'] === null, 'invalid outcome');
        $state = (new SiteChecker(new FakeHttp([new RuntimeException('timeout'), UnitFixtures::response(404)]), new FakeProvider()))->check($site);
        Assert::true($state['online'] === null && $state['response_time_ms'] === null, 'timeout claimed offline');
    }



    #[Test]
    public function skipsOptionalVersionWithoutFalseAttention(): void
    {
        $sample = UnitFixtures::site();
        $input = array_replace($sample, ['version_path' => '']);
        Assert::true(SiteInput::normalize($input)['version_path'] === '', 'empty version rejected');
        unset($input['version_path']);
        Assert::true(SiteInput::normalize($input)['version_path'] === '', 'missing version rejected');
        $http = new FakeHttp([UnitFixtures::response()]);
        $site = SiteInput::normalize($input);
        $state = (new SiteChecker($http, new FakeProvider()))->check($site);
        Assert::true(count($http->requests) === 1 && $state['last_error'] === null && $state['online'] === 1, 'optional version requested or caused error');
        Assert::true($state['deployed_version'] === null && $state['deployed_commit'] === null && $state['open_issues'] === 4, 'Git data lost or installed version fabricated');
        $presented = Presenter::site($site + $state);
        Assert::true(!$presented['attention'] && !$presented['tracks_version'] && $presented['comparison'] === 'Версия сайта не отслеживается', 'unconfigured version flagged attention');
        (new \Tablo\Tests\Support\FixtureRejection(\Closure::fromCallable(static fn () => SiteInput::normalize(array_replace($sample, ['version_path' => '//evil.example'])))))->verify('optional field accepts invalid nonempty value');
    }
}
