<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

final class FixtureGitHubAudit
{
    public static function request(string $path, #[\SensitiveParameter] string $authorization): void
    {
        $log = getenv('TABLO_TEST_GITHUB_LOG');
        if ($log) { file_put_contents($log, $path . "\n", FILE_APPEND); }
        if ($log && getenv('TABLO_TEST_ENDPOINT_AUDIT') === '1') {
            $endpointLeaked = str_contains($authorization, Tablo\Tests\Support\EndpointFixture::tokenA())
                || str_contains($authorization, Tablo\Tests\Support\EndpointFixture::tokenB());
            file_put_contents($log . '.endpoint-audit', json_encode(['endpoint_leaked' => $endpointLeaked], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        }

    }
}
