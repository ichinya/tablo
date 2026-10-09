<?php
declare(strict_types=1);

// Only generated/synthetic credentials; no secret is passed in argv.
$curl = curl_init($argv[1] . '/login');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 10,
    CURLOPT_COOKIEFILE => $argv[2], CURLOPT_COOKIEJAR => $argv[2], CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $argv[3], 'password' => 'fixture-password'])]);
if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 303) { exit(1); }
