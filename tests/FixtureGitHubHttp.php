<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';
require_once __DIR__ . '/FixtureGitHubResponse.php';
require_once __DIR__ . '/FixtureGitHubAudit.php';
require_once __DIR__ . '/FixtureGitHubStatus.php';
require_once __DIR__ . '/FixtureGitHubBody.php';

// Synthetic API transport for isolated HTTP/browser tests. Never used by public/index.php.
if (getenv('TABLO_TEST_ENDPOINT_AUDIT') === '1') {
    \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
    \Tablo\RuntimeIni::set('zend.exception_string_param_max_len', value: '8192');
}

final class FixtureGitHubHttp extends Tablo\HttpClient
{
    public function get(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): array
    {
        if (!str_starts_with($url, 'https://api.github.com/')) {
            throw new RuntimeException('Unexpected GitHub origin');
        }
        $path = parse_url($url, PHP_URL_PATH);
        $authorization = implode("\n", $headers);
        $status = 200;
        $responseHeaders = [];
        FixtureGitHubAudit::request($path, $authorization);
        $status = FixtureGitHubStatus::select($path, $authorization, $headers);
        $response = new FixtureGitHubResponse($status);
        FixtureGitHubBody::select($path, $url, $authorization, $headers, $response);
        $status = $response->status;
        $body = $response->body;
        $responseHeaders = $response->headers;
        // An upstream error can reflect sensitive headers; application must discard it.
        if ($status !== 200) { $body = ['message' => 'Reflected ' . $authorization]; }
        return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'time_ms' => 1, 'headers' => $responseHeaders];
    }
}
