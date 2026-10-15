<?php
declare(strict_types=1);

// Fresh CLI process using the real connection, vault, persisted policy and cURL adapter.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = Tablo\Database::connect(getenv('TABLO_TEST_BUDGET_DB'));
$vault = new Tablo\TokenVault(getenv('TABLO_TEST_BUDGET_KEY'));
$sites = new Tablo\SiteRepository($db, $vault);
$http = new Tablo\Tests\Support\MeasuredGitHubHttp(getenv('TABLO_TEST_BUDGET_BASE'));
$connection = new Tablo\GitHubConnection($sites, $http);
$outcomes = [];
foreach ($sites->all() as $site) {
    try { $connection->provider($site)->getLatestRelease($site['repository']); $outcomes[] = 'ok'; }
    catch (Tablo\GitHubFailure $error) { $outcomes[] = $error->reason; }
}
echo json_encode(['outcomes' => $outcomes, 'core' => $http->core, 'search' => $http->search], JSON_THROW_ON_ERROR);
