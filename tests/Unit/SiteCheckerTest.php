<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Presenter;
use Tablo\SiteChecker;
use Tablo\SiteRepository;

use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;

final class SiteCheckerTest
{
    #[Test]
    public function checksHealthVersionAndIndependentGitMetrics(): void
    {
        $sample = UnitFixtures::site();
        $site = SiteRepository::normalize($sample);
        $http = new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, '{"version":"1.3.1","commit":"a61de82"}')]);
        $state = (new SiteChecker($http, new FakeProvider()))->check($site);
        Assert::true($state['online'] === 1 && $state['response_time_ms'] === 87 && $state['last_error'] === null, 'health outcome');
        Assert::true($state['open_issues'] === 4 && $state['open_prs'] === 1, 'issue/pr counts mixed');
        Assert::true($state['deployed_commit'] === 'a61de82', 'commit ignored');
    }

    #[Test]
    public function preservesUnknownPartialFailures(): void
    {
        $sample = UnitFixtures::site();
        $provider = new FakeProvider();
        $provider->fail = true;
        $http = new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, 'not json')]);
        $state = (new SiteChecker($http, $provider))->check(SiteRepository::normalize($sample));
        Assert::true($state['online'] === 1 && $state['latest_release'] === null && $state['deployed_version'] === null, 'partial outcome fabricated');
        Assert::true($state['open_issues'] === 4 && str_contains($state['last_error'], 'rate limit'), 'partial metadata lost');
    }

    #[Test]
    public function distinguishesOfflineFromTransportFailures(): void
    {
        $sample = UnitFixtures::site();
        $site = SiteRepository::normalize($sample);
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
        Assert::true(SiteRepository::normalize($input)['version_path'] === '', 'empty version rejected');
        unset($input['version_path']);
        Assert::true(SiteRepository::normalize($input)['version_path'] === '', 'missing version rejected');
        $http = new FakeHttp([UnitFixtures::response()]);
        $site = SiteRepository::normalize($input);
        $state = (new SiteChecker($http, new FakeProvider()))->check($site);
        Assert::true(count($http->requests) === 1 && $state['last_error'] === null && $state['online'] === 1, 'optional version requested or caused error');
        Assert::true($state['deployed_version'] === null && $state['deployed_commit'] === null && $state['open_issues'] === 4, 'Git data lost or installed version fabricated');
        $presented = Presenter::site($site + $state);
        Assert::true(!$presented['attention'] && !$presented['tracks_version'] && $presented['comparison'] === 'Версия сайта не отслеживается', 'unconfigured version flagged attention');
        UnitFixtures::rejects(static fn () => SiteRepository::normalize(array_replace($sample, ['version_path' => '//evil.example'])), 'optional field accepts invalid nonempty value');
    }
}
