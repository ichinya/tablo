<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\SiteInput;

use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\UnitFixtures;

final class VersionFailureEvidenceTest
{


    #[Test]
    public function preservesHttpStatusForFailedVersionChecks(): void
    {
        $site = SiteInput::normalize(UnitFixtures::site());
        foreach ([404, 503] as $status) {
            $http = new FakeHttp([UnitFixtures::response(),
                UnitFixtures::response($status, '{"version":"must-not-be-read","commit":"abcdef1"}')]);
            $state = (new SiteChecker($http, new FakeProvider()))->check($site);
            Assert::same($state['last_error'], 'Version: HTTP ' . $status);
            Assert::same($state['deployed_version'], null);
            Assert::same($state['deployed_commit'], null);
            Assert::same($state['online'], 1);
            Assert::same($state['health_error_code'], null);
            Assert::same($state['health_http_status'], 200);
            Assert::same($state['latest_release'], 'v1.3.2');
            Assert::same($state['latest_commit'], str_repeat('a', times: 40));
            Assert::same($state['open_issues'], 4);
            Assert::same($state['open_prs'], 1);
        }
    }



    #[Test]
    public function keepsVersionFailureDetailsSafe(): void
    {
        $site = SiteInput::normalize(UnitFixtures::site());
        $generic = 'Version: Некорректный ответ проверки версии.';
        foreach ([
            [UnitFixtures::response(200, 'private-response-body'), $generic],
            [UnitFixtures::response(200, '{"version":{"private-token":"secret"}}'), $generic],
            [new RuntimeException('https://private-user:private-token@example.com/private-body'), $generic],
            [HttpFailure::fromCurl(CURLE_SSL_CACERT_BADFILE), 'Version: ' . HttpFailure::MESSAGES['tls']],
        ] as [$response, $message]) {
            $state = (new SiteChecker(new FakeHttp([UnitFixtures::response(), $response]), new FakeProvider()))->check($site);
            Assert::same($state['last_error'], $message);
            Assert::false(str_contains($state['last_error'], 'private-'));
            Assert::same($state['online'], 1);
            Assert::same($state['health_error_code'], null);
            Assert::same($state['deployed_version'], null);
            Assert::same($state['deployed_commit'], null);
            Assert::same($state['latest_release'], 'v1.3.2');
            Assert::same($state['open_issues'], 4);
        }
    }
}
