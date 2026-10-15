<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ReadApiRequest;
use Tablo\ReadApiResponse;
use Tablo\ReadApiToken;
use Testo\Assert;
use Testo\Test;

final class ReadApiRequestTest
{
    #[Test]
    public function bearerSyntaxIsBoundedCanonicalAndIgnoresCookies(): void
    {
        $secret = ReadApiToken::generate();
        Assert::true(hash_equals($secret, ReadApiRequest::bearer(['HTTP_AUTHORIZATION' => 'bEaReR ' . $secret]) ?? ''));
        foreach (['', 'Basic abc', 'Bearer ', 'Bearer ' . strtoupper($secret), 'Bearer ' . $secret . ', Bearer ' . $secret,
            'Bearer  ' . $secret, "Bearer {$secret}\n", str_repeat('x', times: 257)] as $header) {
            Assert::null(ReadApiRequest::bearer(['HTTP_AUTHORIZATION' => $header]));
        }
        Assert::null(ReadApiRequest::bearer(['HTTP_COOKIE' => 'tablo_session=synthetic']));
        Assert::null(ReadApiRequest::bearer(['HTTP_AUTHORIZATION' => 'Bearer ' . $secret, 'REDIRECT_HTTP_AUTHORIZATION' => 'different']));
    }

    #[Test]
    public function rawCanonicalPagingRetainsInt64AndRejectsCollapsedOrEncodedInputs(): void
    {
        foreach (['limit=1&after_id=0', 'after_id=9223372036854775807&limit=100'] as $query) {
            $parsed = ReadApiRequest::parse(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/sites?' . $query]);
            Assert::instanceOf($parsed, ReadApiRequest::class);
        }
        foreach (['limit=0', 'limit=101', 'limit=01', 'limit=1&limit=2', 'limit[]=1', '%6cimit=1', 'limit=%31', 'limit=+1',
            'limit=1.0', 'limit=1e1', 'limit=١', 'after_id=-1', 'after_id=00', 'after_id=9223372036854775808', 'after_id=9999999999999999999999',
            'callback=x', 'token=secret', 'limit=', 'limit=%', 'limit=1;', 'limit=1&', '', str_repeat('x', times: 1025)] as $query) {
            $parsed = ReadApiRequest::parse(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/sites?' . $query]);
            Assert::instanceOf($parsed, ReadApiResponse::class); Assert::same($parsed->status, 400);
        }
    }

    #[Test]
    public function routeMethodBodyAndDetailErrorsHaveFixedPrecedence(): void
    {
        foreach (['/api/v2/sites' => 404, '/api/v1/other' => 404, '/api/v1/sites/0' => 400, '/api/v1/sites/01' => 400,
            '/api/v1/sites/1?limit=1' => 400, '/api/v1/sites/1/' => 400, '/api/v1/sites/%31' => 400, '/api/v1/sites/1%2f2' => 400] as $uri => $status) {
            $result = ReadApiRequest::parse(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri]);
            Assert::instanceOf($result, ReadApiResponse::class); Assert::same($result->status, $status);
        }
        foreach (['POST', 'HEAD', 'OPTIONS', 'DELETE'] as $method) {
            $result = ReadApiRequest::parse(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/api/v1/sites']);
            Assert::instanceOf($result, ReadApiResponse::class); Assert::same($result->status, 405);
        }
        foreach ([['CONTENT_LENGTH' => '1'], ['HTTP_TRANSFER_ENCODING' => 'chunked']] as $extra) {
            $result = ReadApiRequest::parse($extra + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/sites']);
            Assert::instanceOf($result, ReadApiResponse::class); Assert::same($result->status, 400);
        }
        $result = ReadApiRequest::parse(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/sites'], bodyBytes: 1);
        Assert::instanceOf($result, ReadApiResponse::class); Assert::same($result->status, 400);
    }
}
