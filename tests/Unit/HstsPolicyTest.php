<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HstsConfigurationError;
use Tablo\HstsPolicy;
use Testo\Assert;
use Testo\Test;

require_once dirname(__DIR__, levels: 2) . '/app/HstsPolicy.php';


#[Test]
function hsts_exact_modes_and_canonical_age_refuse_without_leaking_values(): void
{
    foreach ([false, '', 'off'] as $mode) {
        Assert::null((new HstsPolicy($mode, trust: 'invalid unrelated login trust'))->headerValue(['HTTPS' => 'on']));
    }
    foreach (['0', '1', '300', '31536000'] as $age) {
        Assert::same((new HstsPolicy('direct', $age))->headerValue(['HTTPS' => 'on']), 'max-age=' . $age);
        Assert::null((new HstsPolicy('off', $age))->headerValue(['HTTPS' => 'on']));
    }
    foreach (['OFF', 'Direct', ' proxy', "direct\n", 'on', 'secret-mode-canary'] as $mode) {
        hsts_refused($mode, age: '300', setting: 'TABLO_HSTS_MODE');
    }
    foreach (['31536001', '999999999999', '-1', '+1', '01', '00', '1.0', '1e2', 'пј‘', ' 1', "1\n", "1\0"] as $age) {
        foreach (['off', 'direct'] as $mode) { hsts_refused($mode, $age, setting: 'TABLO_HSTS_MAX_AGE'); }
    }
    foreach ([false, ''] as $age) { hsts_refused('direct', $age, setting: 'TABLO_HSTS_MAX_AGE'); }
}

#[Test]
function hsts_direct_uses_only_exact_server_owned_https_strings(): void
{
    $policy = new HstsPolicy('direct', '300', trust: 'invalid unrelated login trust');
    foreach (['on', '1'] as $value) { Assert::same($policy->headerValue(['HTTPS' => $value]), 'max-age=300'); }
    foreach ([null, false, true, 1, [], 'ON', 'true', 'https', 'off', '0', "on\n"] as $value) {
        Assert::null($policy->headerValue(['HTTPS' => $value, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_FORWARDED' => 'proto=https']));
    }
    Assert::null($policy->headerValue(['SERVER_PORT' => '443', 'HTTP_X_FORWARDED_PROTO' => 'https']));
}

#[Test]
function hsts_proxy_requires_whole_valid_trust_raw_peer_and_one_bounded_https_token(): void
{
    foreach ([false, '', '0.0.0.0', '::', '*', '127.0.0.1/8', '127.0.0.1,', str_repeat('x', times: 4097)] as $trust) {
        try { new HstsPolicy('proxy', '300', $trust); Assert::true(false, 'Invalid trust must fail.'); }
        catch (HstsConfigurationError $error) {
            Assert::same($error->getMessage(), 'Invalid TABLO_TRUSTED_PROXIES configuration.'); Assert::null($error->getPrevious());
        }
    }
    $policy = new HstsPolicy('proxy', '0', '192.0.2.10,2001:db8::10');
    foreach (['192.0.2.10', '::ffff:192.0.2.10', '2001:0DB8:0:0:0:0:0:10'] as $peer) {
        foreach (['https', 'HTTPS', " \thTtPs\t "] as $proto) {
            Assert::same($policy->headerValue(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_PROTO' => $proto]), 'max-age=0');
        }
    }
    foreach ([null, [], '', '127.1', '192.0.2.11', '192.0.2.10:80', '[2001:db8::10]', "192.0.2.10\n"] as $peer) {
        Assert::null($policy->headerValue(['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTPS' => 'on']));
    }
    foreach ([null, [], 1, '', 'http', 'https,https', 'https, http', 'https https', '"https"', 'https;proto=https', "ht\ttps", "https\r", "https\n", "https\0", str_repeat(' ', times: 60) . 'https'] as $proto) {
        Assert::null($policy->headerValue(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => $proto, 'HTTPS' => 'on']));
    }
    Assert::same($policy->headerValue(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => str_repeat(' ', times: 59) . 'https']), 'max-age=0');
}

function hsts_refused(string $mode, string|false $age, string $setting): void
{
    try { new HstsPolicy($mode, $age); Assert::true(false, 'Invalid configuration must fail.'); }
    catch (HstsConfigurationError $error) {
        Assert::same($error->getMessage(), 'Invalid ' . $setting . ' configuration.'); Assert::null($error->getPrevious());
        Assert::false(str_contains($error->getTraceAsString(), 'secret-mode-canary'));
    }
}
