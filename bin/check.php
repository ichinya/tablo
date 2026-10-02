<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$sites = new Tablo\SiteRepository(Tablo\Database::connect());
$github = new Tablo\GitHubConnection($sites, new Tablo\HttpClient());
$failures = 0;
foreach ($sites->all() as $site) {
    if (!$site['enabled']) {
        continue;
    }
    try {
        $checker = new Tablo\SiteChecker(new Tablo\HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'), $github->provider($site));
        $state = $checker->check($site);
        $stored = $sites->storeCheck($site, $state);
        $failed = !$stored || $state['online'] !== 1 || $state['last_error'] !== null;
    } catch (Tablo\ValidationException) {
        $failed = true;
    }
    echo $site['id'] . ': ' . ($failed ? 'attention' : 'ok') . PHP_EOL;
    $failures += (int) $failed;
}
exit($failures ? 1 : 0);
