<?php
declare(strict_types=1);

// Fresh public provider boundary; synthetic credentials arrive only in process memory.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = Tablo\Database::connect(getenv('TABLO_TEST_PREVIEW_DB'));
$sites = new Tablo\SiteRepository($db, new Tablo\TokenVault(getenv('TABLO_TEST_PREVIEW_KEY')));
$http = new Tablo\Tests\Support\MeasuredGitHubHttp(getenv('TABLO_TEST_PREVIEW_BASE'));
$connection = new Tablo\GitHubConnection($sites, $http);
$secret = getenv('TABLO_TEST_PREVIEW_TOKEN');
$outcome = 'ok';
$traceHasToken = false;
$scope = null;
try {
    $connection->provider(null, ['github_token' => $secret])->getLatestRelease('fixture/control');
    $scope = $sites->equivalentCredentialScope($secret);
} catch (Tablo\ValidationException $error) {
    $outcome = str_contains($error->getMessage(), 'Восстановите его из резервной копии') ? 'key-unavailable' : 'unexpected';
    for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
        $traceHasToken = $traceHasToken || str_contains(json_encode([$cause->getTrace(), (string) $cause], JSON_THROW_ON_ERROR), $secret);
    }
}
echo json_encode(['outcome' => $outcome, 'core' => $http->core, 'scope' => $scope,
    'traceHasToken' => $traceHasToken], JSON_THROW_ON_ERROR);
