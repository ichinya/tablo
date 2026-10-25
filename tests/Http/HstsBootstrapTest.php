<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\HstsFixture;
use Testo\Assert;
use Testo\Test;


#[Test]
function hsts_invalid_configuration_refuses_both_ingresses_before_composer_or_state(): void
{
    foreach ([['TABLO_HSTS_MODE' => 'private-hsts-canary'], ['TABLO_HSTS_MODE' => 'direct', 'TABLO_HSTS_MAX_AGE' => '01'],
        ['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => 'private-hsts-canary']] as $environment) {
        $fixture = new HstsFixture($environment, autoload: 'absent');
        try {
            foreach (['/setup', '/api/v1/sites'] as $path) {
                $response = $fixture->request($path); Assert::same($response['status'], 500); HstsFixture::header($response, null);
                Assert::false(str_contains($response['body'], 'private-hsts-canary'));
                Assert::false(str_contains(strtolower($response['headers']), 'set-cookie:'));
            }
            Assert::false(is_dir($fixture->source . '/storage'));
            Assert::false(str_contains($fixture->server->diagnostics(), 'private-hsts-canary'));
            Assert::false(str_contains($fixture->server->diagnostics(), 'vendor/autoload.php'));
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}

#[Test]
function hsts_missing_composer_and_policy_have_fixed_responses_and_early_401_is_key_free(): void
{
    $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1'], autoload: 'absent');
    try {
        $headers = ['X-Forwarded-Proto: https'];
        $early = $fixture->request('/api/v1/sites', headers: $headers); Assert::same($early['status'], 401); HstsFixture::header($early, 'max-age=300');
        Assert::true(str_contains($early['headers'], 'WWW-Authenticate: Bearer'));
        $web = $fixture->request('/setup', headers: $headers); Assert::same($web['status'], 500); HstsFixture::header($web, 'max-age=300');
        Assert::false(is_dir($fixture->source . '/storage'));
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $fixture->close(); }
    $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1'], autoload: 'missing-policy');
    try {
        foreach (['/setup', '/api/v1/sites'] as $path) {
            $response = $fixture->request($path, headers: $headers); Assert::same($response['status'], 500); HstsFixture::header($response, null);
        }
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $fixture->close(); }
}

#[Test]
function hsts_health_never_reads_malformed_hsts_and_retains_capture_failure_boundaries(): void
{
    foreach (['complete' => 200, 'absent' => 503, 'output' => 503, 'headers' => 503, 'status' => 503, 'throw' => 503, 'closed' => 503, 'buffers' => 503] as $fault => $status) {
        $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'private-hsts-canary'], $fault);
        try {
            foreach ([['/_tablo/health', 'GET', $status], ['/_tablo/health?', 'GET', 404], ['/_tablo/health', 'POST', 405]] as [$path, $method, $expected]) {
                $response = $fixture->request($path, headers: ['Host: 127.0.0.1'], method: $method);
                Assert::same($response['status'], $expected); HstsFixture::header($response, null);
                Assert::false(str_contains(strtolower($response['headers']), 'set-cookie:'));
                Assert::false(str_contains($response['body'], 'private-hsts-canary'));
            }
            Assert::false(is_dir($fixture->source . '/storage'));
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}

#[Test]
function hsts_pre_composer_snapshot_survives_conflicting_dotenv_and_dotenv_alone_cannot_enable_it(): void
{
    foreach (['off' => null, 'proxy' => 'max-age=300'] as $mode => $expected) {
        $fixture = new HstsFixture(['TABLO_HSTS_MODE' => $mode, 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1'],
            dotenv: "TABLO_HSTS_MODE=direct\nTABLO_HSTS_MAX_AGE=999\nTABLO_TRUSTED_PROXIES=192.0.2.10\n");
        try {
            $response = $fixture->request('/setup', headers: ['X-Forwarded-Proto: https']); Assert::same($response['status'], 200); HstsFixture::header($response, $expected);
            HstsFixture::header($fixture->request('/api/v1/sites', headers: ['X-Forwarded-Proto: https']), $expected);
            $fixture->authenticate();
            $afterComposer = $fixture->request('/api/v1/sites', headers: ['X-Forwarded-Proto: https',
                'Authorization: Bearer ' . \Tablo\Tests\Support\ReadApiFixture::wrong()]);
            Assert::same($afterComposer['status'], 401); HstsFixture::header($afterComposer, $expected);
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
    // Empty proc_open entries may disappear on Windows; either absent or empty is off.
    $fixture = new HstsFixture(['TABLO_HSTS_MODE' => '', 'TABLO_HSTS_MAX_AGE' => ''],
        dotenv: "TABLO_HSTS_MODE=proxy\nTABLO_HSTS_MAX_AGE=999\nTABLO_TRUSTED_PROXIES=127.0.0.1\n");
    try {
        foreach (['/setup', '/api/v1/sites'] as $path) { HstsFixture::header($fixture->request($path, headers: ['X-Forwarded-Proto: https']), null); }
    } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
    finally { $fixture->close(); }
}
