<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Tablo\Tests\Support\WorkerHttp;
use Testo\Assert;
use Testo\Test;

final class HistoryDiagnosticsTest
{
    #[Test]
    public function realTransportJsonVersionAndGitFailuresPreserveIndependentResultsAndBoundedProjection(): void
    {
        $directory = new TemporaryDirectory('tablo-history-diagnostics-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/history-diagnostics-router.php');
            $db = Database::connect($directory->path . '/db.sqlite');
            $sites = new SiteRepository($db, new TokenVault($directory->path . '/never-created.key'));
            $cases = [
                ['health', 'broken', 'access', 1, null, 'invalid-data', 'access', null],
                ['broken', 'version', 'good', null, 'invalid-response', null, null, null],
                ['missing', 'version', 'good', null, 'invalid-response', null, null, null],
                ['truncated', 'version', 'good', null, 'invalid-response', null, null, null],
                ['health', 'unavailable', 'bad-release', 1, null, 'http', 'release-unconfirmed', null],
                ['health', 'version', 'wrong-branch', 1, null, null, null, 'branch-unconfirmed'],
                ['health', '', 'no-release', 1, null, null, null, null],
                ['health', 'version', 'rate', 1, null, null, 'rate-limit', 'rate-limit'],
            ];
            foreach ($cases as [$health, $version, $repo, $online, $healthCode, $versionCode, $releaseCode, $commitCode]) {
                $id = $sites->save(array_replace(UnitFixtures::site(), ['url' => $server->base, 'repository' => 'fixture/' . $repo,
                    'health_path' => '/' . $health, 'version_path' => $version === '' ? '' : '/' . $version,
                    'health_check_mode' => 'json', 'health_json_path' => '$.status', 'health_json_expected_value' => 'ok']));
                $site = $sites->find($id);
                $http = new WorkerHttp($server->base);
                $state = (new SiteChecker($http, (new GitHubConnection($sites, $http))->provider($site)))->check($site);
                Assert::true($sites->storeCheck($site, $state));
                $row = $db->query('SELECT * FROM check_history WHERE site_id=' . $id)->fetch();
                Assert::same($row['online'], $online, $repo . '/' . $health);
                Assert::same($row['health_error_code'], $healthCode);
                Assert::same($row['version_error_code'], $versionCode);
                Assert::same($row['release_error_code'], $releaseCode);
                Assert::same($row['commit_error_code'], $commitCode);
                Assert::same($row['version_status'], $version === '' ? 'skipped' : ($versionCode === null ? 'ok' : 'error'));
                if ($versionCode === 'http') { Assert::same($row['version_http_status'], 503); }
                if ($releaseCode === 'access') { Assert::same($row['release_http_status'], 403); }
                if ($version === 'version') { Assert::same($row['deployed_version'], 'v1'); Assert::same($row['deployed_commit'], 'abcdef1'); }
                if ($repo === 'no-release') { Assert::same($row['latest_release'], null); Assert::same($row['release_error_code'], null); }
                foreach (['synthetic-history-body-marker', 'synthetic-history-header-marker', $server->base,
                    'last_error', 'github_token', 'git_token_id', 'worker_service'] as $private) {
                    Assert::false(str_contains(json_encode($row), $private), $private);
                }
                Assert::same($sites->find($id)['online'], $online, 'Git/version failures do not change accepted health');
            }
            Assert::false(is_file($directory->path . '/never-created.key'));
        } finally { unset($row, $state, $site, $http, $sites, $db); $server?->close(); $directory->close(); }
    }
}
