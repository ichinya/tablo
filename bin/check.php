<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Check operation failed.');
});
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $vault = Tablo\TokenVault::configured();
    $db = Tablo\Database::connect(vault: $vault);
    $vault ??= Tablo\TokenVault::forDatabase($db);
    $sites = new Tablo\SiteRepository($db, $vault);
    $sites->assertWorkerKeyAvailable();
    $github = new Tablo\GitHubConnection($sites, new Tablo\HttpClient());
    $failures = 0;
    foreach ($sites->all() as $site) {
        if (!$site['enabled']) { continue; }
        $sites->assertWorkerKeyAvailable();
        try {
            $checker = new Tablo\SiteChecker(new Tablo\HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'), $github->provider($site));
            $state = $checker->check($site);
            $sites->assertWorkerKeyAvailable();
            $stored = $sites->storeCheck($site, $state);
            $failed = !$stored || $state['online'] !== 1 || $state['last_error'] !== null;
        } catch (Tablo\ValidationException) { $failed = true; }
        echo $site['id'] . ': ' . ($failed ? 'attention' : 'ok') . PHP_EOL;
        $failures += (int) $failed;
    }
    exit($failures ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, "Check failed. Check installation configuration and local storage.\n");
    if ($error instanceof Tablo\SharedKeyFailure && $error->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        fwrite(STDERR, Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
    }
    exit(1);
}
