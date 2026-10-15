<?php
declare(strict_types=1);
require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

// Only generated/synthetic credentials; no secret is passed in argv.
$curl = curl_init(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 1) . '/login');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 10,
    CURLOPT_COOKIEFILE => \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 2), CURLOPT_COOKIEJAR => \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 2), CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['_csrf' => \Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 3), 'password' => \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password')])]);
if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 303) { exit(1); }
