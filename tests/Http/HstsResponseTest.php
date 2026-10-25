<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\HstsFixture;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class HstsResponseTest
{
    #[Test]
    public function actualIndexPreservesHtmlJsonRedirectAndRefusalResponses(): void
    {
        $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1']);
        $headers = ['X-Forwarded-Proto: https', 'X-Forwarded-For: 192.0.2.42'];
        try {
            foreach (['/setup' => 200, '/' => 303] as $path => $status) {
                $response = $fixture->request($path, headers: $headers); Assert::same($response['status'], $status); HstsFixture::header($response, 'max-age=300');
            }
            $csrf = $fixture->authenticate();
            $cases = [['/', null, 200], ['/settings', null, 200], ['/setup', null, 404], ['/absent', null, 404],
                ['/settings/session-timeout', ['_csrf' => $csrf, 'session_idle_timeout_minutes' => 'invalid'], 422],
                ['/settings/session-timeout', ['_csrf' => 'invalid', 'session_idle_timeout_minutes' => '2'], 419],
                ['/sites/branches', ['_csrf' => $csrf, 'repository' => ''], 422],
                ['/sites/branches', ['_csrf' => $csrf, 'site_id' => '999'], 404]];
            foreach ($cases as [$path, $data, $status]) {
                $response = $fixture->request($path, $data, $headers); Assert::same($response['status'], $status, $path); HstsFixture::header($response, 'max-age=300');
                foreach (['X-Frame-Options: DENY', 'Referrer-Policy: same-origin', 'X-Content-Type-Options: nosniff', 'Cache-Control: no-store'] as $field) {
                    Assert::true(str_contains($response['headers'], $field));
                }
            }
            $large = $fixture->request('/settings', ['_csrf' => $csrf, 'extra' => str_repeat('x', times: 70_000)], $headers);
            Assert::same($large['status'], 413); HstsFixture::header($large, 'max-age=300');
            $logout = $fixture->request('/logout', ['_csrf' => $csrf], $headers); Assert::same($logout['status'], 303); HstsFixture::header($logout, 'max-age=300');
            $login = $fixture->request('/login', headers: $headers); Assert::same($login['status'], 200); HstsFixture::header($login, 'max-age=300');
            $signedIn = $fixture->request('/login', ['_csrf' => WebFixture::csrf($login), 'password' => FixtureCredentials::value('fixture-password')], $headers);
            Assert::same($signedIn['status'], 303); HstsFixture::header($signedIn, 'max-age=300');
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function hstsAndSecureCookieRemainIndependentAndHttpDoesNotRedirect(): void
    {
        foreach (['off', 'direct', 'proxy'] as $mode) {
            foreach (['0', '1'] as $secure) {
                $fixture = new HstsFixture(['TABLO_HSTS_MODE' => $mode, 'TABLO_HSTS_MAX_AGE' => '0', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1', 'TABLO_COOKIE_SECURE' => $secure]);
                try {
                    $response = $fixture->request('/setup', headers: ['X-Forwarded-Proto: https']); Assert::same($response['status'], 200);
                    HstsFixture::header($response, $mode === 'proxy' ? 'max-age=0' : null);
                    Assert::same(preg_match('/^Set-Cookie:.*; secure/mi', $response['headers']), $secure === '1' ? 1 : 0);
                    $http = $fixture->request('/setup', headers: ['X-Forwarded-Proto: http']); Assert::same($http['status'], 200); HstsFixture::header($http, null);
                } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
                finally { $fixture->close(); }
            }
        }
    }
}

#[Test]
function hsts_genuine_index_json_200_keeps_provider_and_session_flow_with_isolated_outbound_transport(): void
{
    $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1'], autoload: 'branches');
    try {
        $csrf = $fixture->authenticate();
        $response = $fixture->request('/sites/branches', ['_csrf' => $csrf, 'repository' => 'fixture/public'],
            ['X-Forwarded-Proto: https', 'X-Forwarded-For: 192.0.2.42']);
        Assert::same($response['status'], 200, $response['body']); HstsFixture::header($response, 'max-age=300');
        Assert::same(json_decode($response['body'], associative: true, flags: JSON_THROW_ON_ERROR),
            ['repository' => 'fixture/public', 'default_branch' => 'main', 'branches' => ['main']]);
        Assert::true(str_contains(strtolower($response['headers']), 'content-type: application/json'));
        Assert::true(str_contains($response['headers'], 'Cache-Control: no-store'));
        $calls = \Tablo\RequiredFile::bytes(file_get_contents($fixture->source . '/provider-calls.jsonl'));
        Assert::true(str_contains($calls, '/repos/fixture/public/branches'));
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $fixture->close(); }
}

#[Test]
function hsts_genuine_index_expiry_keeps_html_303_and_json_401_with_the_same_policy(): void
{
    foreach ([['/', null, 303], ['/sites/branches', [], 401]] as [$path, $data, $status]) {
        $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1']);
        try {
            $fixture->authenticate();
            $cookies = \Tablo\RequiredFile::bytes(file_get_contents($fixture->directory->path . '/cookies')); $match = [];
            Assert::same(preg_match('/\ttablo_session\t([a-zA-Z0-9,-]+)\s*$/m', $cookies, $match), 1);
            $session = $fixture->source . '/storage/sessions/sess_' . ($match[1] ?? '');
            $bytes = \Tablo\RequiredFile::bytes(file_get_contents($session));
            $expired = preg_replace('/(authenticated_at|last_activity_at|session_observed_at)\|i:[0-9]+;/', replacement: '$1|i:1;', subject: $bytes);
            Assert::notNull($expired); Assert::notSame($expired, $bytes); file_put_contents($session, $expired);
            $response = $fixture->request($path, $data, ['X-Forwarded-Proto: https']); Assert::same($response['status'], $status); HstsFixture::header($response, 'max-age=300');
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}
