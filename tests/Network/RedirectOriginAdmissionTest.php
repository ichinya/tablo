<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\Tests\Support\RedirectServer;
use Testo\Assert;
use Testo\Test;

final class RedirectOriginAdmissionTest
{


    #[Test]
    public function rejectsOtherOriginsWithZeroDestinationHitsAndNoCredentialForwarding(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 4);
            return;
        }
        $source = new RedirectServer(); $destination = new RedirectServer();
        try {
            foreach (['scheme', 'host', 'private', 'port?port=' . \Tablo\Tests\Support\FixtureValue::integer(parse_url($destination->base, PHP_URL_PORT))] as $case) {
                $before = count($source->rows());
                \Tablo\Tests\Support\RedirectAssertions::reject($source->base . '/cross/' . $case, 'redirect-origin', headers: \Tablo\Tests\Support\RedirectAssertions::headers());
                Assert::same(count($source->rows()) - $before, 1);
                Assert::same($destination->rows(), []);
                Assert::true(\Tablo\Tests\Support\FixtureValue::at($source->rows(), $before)['bearer']);
            }
            foreach ([['Cookie: synthetic-redirect-token'], ['Host: other.example'], ['X-Key: synthetic-redirect-token'],
                ['Authorization: Bearer synthetic-redirect-token', 'Referer: secret'], ["Authorization: Bearer x\r\nX: y"]] as $headers) {
                $before = count($source->rows());
                \Tablo\Tests\Support\RedirectAssertions::reject($source->base . '/final', 'check-error', headers: $headers);
                Assert::same(count($source->rows()), $before);
            }
        } finally { $destination->close(); $source->close(); }
    }



    #[Test]
    public function rejectsMissingEmptyDuplicateHostileLocationAndUsesOnlyFinalHeaders(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 4);
            return;
        }
        $server = new RedirectServer();
        try {
            foreach (['missing', 'empty'] as $case) { \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/header/' . $case, 'redirect-location-missing'); }
            foreach (['duplicate', 'conflicting', 'folded', 'control', 'userinfo', 'fragment', 'malformed'] as $case) {
                $before = count($server->rows());
                \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/header/' . $case, 'redirect-location-invalid', headers: \Tablo\Tests\Support\RedirectAssertions::headers());
                Assert::same(count($server->rows()) - $before, 1);
            }
            Assert::same((new HttpClient(true))->getEndpoint($server->base . '/header/interim')['status'], 200);
            Assert::same((new HttpClient(true))->getEndpoint($server->base . '/header/interim-identical')['status'], 200);
            $before = count($server->rows());
            $outcome = [];
            try {
                $response = (new HttpClient(true))->getEndpoint($server->base . '/header/interim-conflicting');
                Assert::same($response['status'], 200);
                Assert::same(count($server->rows()) - $before, 2, 'only an unambiguous final Location can route');
                $outcome = ['status' => 200];
            } catch (HttpFailure $error) {
                Assert::same($error->reason, 'redirect-location-invalid');
                Assert::same(count($server->rows()) - $before, 1, 'libcurl refuses ambiguous Location before final callback');
                $outcome = ['reason' => $error->reason];
            }
            $report = getenv('TABLO_REDIRECT_CONTROL_REPORT');
            if ($report) { file_put_contents($report, \Tablo\Tests\Support\FixtureValue::string(json_encode(['curl_version' => \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::fetched(curl_version()), 'version'),
                'interim_conflict' => $outcome])) . "\n", FILE_APPEND | LOCK_EX); }
            $before = count($server->rows());
            Assert::same((new HttpClient(true))->getEndpoint($server->base . '/header/trailer')['status'], 200);
            Assert::same(count($server->rows()) - $before, 1);
        } finally { $server->close(); }
    }
}
