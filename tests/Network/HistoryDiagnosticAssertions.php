<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class HistoryDiagnosticAssertions
{
    public static function row(array $row, array $expected, string $base): void
    {
                [$health, $version, $repo, $online, $healthCode, $versionCode, $releaseCode, $commitCode] = $expected;
                Assert::same($row['online'], $online, $repo . '/' . $health);
                Assert::same($row['health_error_code'], $healthCode);
                Assert::same($row['version_error_code'], $versionCode);
                Assert::same($row['release_error_code'], $releaseCode);
                Assert::same($row['commit_error_code'], $commitCode);
                Assert::same($row['version_status'], $version === '' ? 'skipped' : (match (true) { ($versionCode === null) => 'ok', default => 'error' }));
                if ($versionCode === 'http') { Assert::same($row['version_http_status'], 503); }
                if ($releaseCode === 'access') { Assert::same($row['release_http_status'], 403); }
                if ($version === 'version') { Assert::same($row['deployed_version'], 'v1'); Assert::same($row['deployed_commit'], 'abcdef1'); }
                if ($repo === 'no-release') { Assert::same($row['latest_release'], null); Assert::same($row['release_error_code'], null); }
                foreach (['synthetic-history-body-marker', 'synthetic-history-header-marker', $base,
                    'last_error', 'github_token', 'git_token_id', 'worker_service'] as $private) {
                    Assert::false(str_contains(json_encode($row), $private), $private);
                }

    }
}
