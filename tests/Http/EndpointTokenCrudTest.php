<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenCrudTest
{
    #[Test]
    public function authenticatedCrudKeepsPasswordEmptyAndDoesNotLeakThroughPublicOrBranchResponses(): void
    {
        $web = new WebFixture(environment: ['TABLO_TEST_ENDPOINT_AUDIT' => '1']);
        $sites = null;
        try {
            $csrf = $web->authenticate();
            $input = [...SiteInput::defaults(), 'name' => 'Endpoint credential', 'url' => 'https://example.com/',
                'repository' => 'fixture/public', '_csrf' => $csrf, 'endpoint_token' => EndpointFixture::tokenA()];
            Assert::same($web->request('/sites/new', $input)['status'], 303);
            $sites = new SiteRepository($web->database());
            $cipher = \Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token'];
            Assert::true(is_string($cipher) && str_starts_with($cipher, 'v1:'));
            $cipher = \Tablo\Tests\Support\FixtureValue::string($cipher);
            $edit = $web->request('/sites/1/edit');
            Assert::same($edit['status'], 200);
            Assert::true(preg_match('/<input[^>]+id="endpoint_token"[^>]+value=""/', $edit['body']) === 1);
            Assert::true(str_contains($edit['body'], 'name="remove_endpoint_token"'));
            Assert::false(str_contains($edit['body'], $cipher) || str_contains($edit['body'], EndpointFixture::tokenA()));
            foreach ([null, ''] as $blank) {
                $keep = $input;
                switch (true) {
case ($blank === null):
 unset($keep['endpoint_token']);
    break;
default:
 $keep['endpoint_token'] = '';
    break;
}
                Assert::same($web->request('/sites/1/edit', $keep)['status'], 303);
                Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']), $cipher));
            }
            $branches = $web->request('/sites/branches', [...$input, 'site_id' => '1']);
            Assert::same($branches['status'], 200);
            Assert::false(str_contains($branches['body'], EndpointFixture::tokenA()) || str_contains($branches['body'], $cipher));
            foreach (['/', '/sites/1/edit', '/sites/1/delete'] as $path) {
                $response = $web->request($path);
                Assert::false(str_contains($response['body'], $cipher) || str_contains($response['body'], EndpointFixture::tokenA()));
            }
            Assert::same($web->request('/sites/1/edit', [...$input, 'endpoint_token' => EndpointFixture::tokenB()])['status'], 303);
            Assert::false(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']), $cipher));
            $keep = $input; unset($keep['endpoint_token']);
            Assert::same($web->request('/sites/1/edit', [...$keep, 'remove_endpoint_token' => \Tablo\FormCheckbox::VALUE])['status'], 303);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token'], null);
            Assert::false(str_contains($web->request('/sites/1/edit')['body'], 'name="remove_endpoint_token"'));
            foreach (\Tablo\Tests\Support\FixtureFiles::lines($web->directory->path . '/github-calls.log.endpoint-audit', FILE_IGNORE_NEW_LINES) as $line) {
                Assert::false(\Tablo\Tests\Support\FixtureJson::boolean(\Tablo\JsonRecord::decode($line, 4), 'endpoint_leaked'));
            }
            $logs = $web->server->diagnostics();
            Assert::false(str_contains($logs, EndpointFixture::tokenA()) || str_contains($logs, EndpointFixture::tokenB()) || str_contains($logs, $cipher));
        } finally { $sites = null; $web->close(); }
    }

    #[Test]
    public function rejectedValuesStayBlankAndAuthenticationCsrfBodyCapsRemain(): void
    {
        $web = new WebFixture();
        $sites = null;
        try {
            $csrf = $web->authenticate();
            $input = [...SiteInput::defaults(), 'name' => 'Endpoint credential', 'url' => 'https://example.com/',
                'repository' => 'fixture/public', '_csrf' => $csrf, 'endpoint_token' => EndpointFixture::tokenA()];
            Assert::same($web->request('/sites/new', $input)['status'], 303);
            $sites = new SiteRepository($web->database());
            $cipher = \Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']);
            $gitCalls = count(\Tablo\Tests\Support\FixtureFiles::lines($web->directory->path . '/github-calls.log'));
            foreach ([' ', [EndpointFixture::tokenA()], EndpointFixture::tokenA() . "\n", str_repeat(chr(90), times: 513)] as $invalid) {
                $response = $web->request('/sites/1/edit', [...$input, 'endpoint_token' => $invalid]);
                Assert::same($response['status'], 422);
                Assert::true(str_contains($response['body'], 'id="endpoint_token-error"'));
                Assert::false(str_contains($response['body'], EndpointFixture::tokenA()) || str_contains($response['body'], $cipher));
                Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']), $cipher));
            }
            Assert::same($web->request('/sites/1/edit', [...$input, 'remove_endpoint_token' => \Tablo\FormCheckbox::VALUE])['status'], 422);
            Assert::same(count(\Tablo\Tests\Support\FixtureFiles::lines($web->directory->path . '/github-calls.log')), $gitCalls, 'invalid credential intent makes no Git request');
            foreach ([['url' => 'https://' . EndpointFixture::tokenA() . '@example.com/'],
                ['url' => 'https://example.com/?token=' . EndpointFixture::tokenA()],
                ['health_path' => '//example.com/?token=' . EndpointFixture::tokenA()],
                ['version_path' => '//example.com/?token=' . EndpointFixture::tokenA()]] as $bad) {
                $response = $web->request('/sites/1/edit', array_replace($input, $bad));
                Assert::same($response['status'], 422);
                Assert::false(str_contains($response['body'], EndpointFixture::tokenA()));
                Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']), $cipher));
            }
            $response = $web->request('/sites/1/edit', [...$input, 'name' => '', 'health_path' => '/up?build=%2Fstable']);
            Assert::same($response['status'], 422);
            Assert::true(str_contains($response['body'], 'value="/up?build=%2Fstable"'), 'ordinary valid endpoint query survives unrelated validation errors');
            Assert::same($web->request(path: '/sites/1/edit', data: $input, options: new \Tablo\Tests\Support\WebRequestOptions(cookies: \Tablo\Tests\Support\CookiePolicy::Omitted))['status'], 303);
            Assert::same($web->request('/sites/1/edit', [...$input, '_csrf' => 'wrong'])['status'], 419);
            Assert::same($web->request('/sites/1/edit', [...$input, 'padding' => str_repeat(chr(90), times: 17_000)])['status'], 413);
            Assert::true(hash_equals(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']), $cipher));
            Assert::false(str_contains($web->server->diagnostics(), EndpointFixture::tokenA()));
        } finally { $sites = null; $web->close(); }
    }
}
