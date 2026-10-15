<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\SiteChecker;
use Tablo\SiteInput;

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
        $site = SiteInput::normalize($sample);
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
        $state = (new SiteChecker($http, $provider))->check(SiteInput::normalize($sample));
        Assert::true($state['online'] === 1 && $state['latest_release'] === null && $state['deployed_version'] === null, 'partial outcome fabricated');
        Assert::true($state['open_issues'] === 4 && str_contains($state['last_error'], 'rate limit'), 'partial metadata lost');
    }
}
