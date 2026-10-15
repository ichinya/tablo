<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class ReadApiParametersTest
{
    #[Test]
    public function actualWireQueryPathMethodBodyAndHeadStayWithinFixedJsonContract(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web;
        try {
            $secret = $fixture->rotate($web->authenticate());
            foreach (['?limit=0','?limit=101','?limit=01','?limit=1&limit=2','?after_id=00','?after_id=9223372036854775808',
                '?limit[]=1','?%6cimit=1','?limit=%31','?limit=%','?callback=x','/01','/0','/9223372036854775808','/1?limit=1','/1%2f2','/1/','?' . str_repeat('x', times: 1025)] as $suffix) {
                ReadApiFixture::error($fixture->api('/api/v1/sites' . $suffix, $secret), 400, 'invalid_parameter');
            }
            ReadApiFixture::error($fixture->api('/api/v2/sites', $secret), 404, 'not_found');
            foreach (['POST','OPTIONS','DELETE','PATCH'] as $method) {
                $response = $fixture->api(secret: $secret, method: $method); ReadApiFixture::error($response, 405, 'method_not_allowed');
                Assert::true(str_contains($response['headers'], 'Allow: GET'));
            }
            ReadApiFixture::error($fixture->api(secret: $secret, body: 'x'), 400, 'invalid_parameter');
            $head = $fixture->api(secret: $secret, method: 'HEAD'); Assert::same($head['status'], 405); ReadApiFixture::headers($head);
            Assert::true(str_contains($head['headers'], 'Allow: GET')); Assert::same($head['body'], '', 'This SAPI suppresses the HEAD body.');
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}
