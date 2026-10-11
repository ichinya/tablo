<?php
declare(strict_types=1);

// This helper has no autoload dependencies, including for early help/refusals.
require_once dirname(__DIR__) . '/app/check-diagnostics.php';

// Mode detection and the emergency document require no autoloaded code.
$json = in_array('--json', $argv ?? [], true);
$earlyJson = static function (string $status, string $reason): void {
    $exit = $status === 'success' ? 0 : 1;
    echo '{"schema_version":"tablo/check/v1","run":{"status":"' . $status . '","reason":"' . $reason
        . '","exit_code":' . $exit . ',"started_at":null,"finished_at":null},"sites":[]}' . "\n";
};
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Check operation failed.');
});
try {
    if ($json) {
        $arguments = array_slice($argv, 1);
        if (count(array_keys($arguments, '--json', true)) !== 1
            || count(array_keys($arguments, '--help', true)) > 1
            || array_diff($arguments, ['--json', '--help']) !== []) {
            $earlyJson('fatal', 'invalid-arguments');
            Tablo\CheckDiagnostics::write("Usage: php bin/check.php [--json [--help]]\n");
            exit(1);
        }
        if (in_array('--help', $arguments, true)) {
            $earlyJson('success', 'help');
            Tablo\CheckDiagnostics::write("Usage: php bin/check.php [--json [--help]]\n");
            exit(0);
        }
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    $vault = Tablo\TokenVault::configured();
    $db = Tablo\Database::connect(vault: $vault);
    $vault ??= Tablo\TokenVault::forDatabase($db);
    $sites = new Tablo\SiteRepository($db, $vault);
    $sites->assertWorkerKeyAvailable();
    $github = $json ? new Tablo\GitHubConnection($sites, new Tablo\HttpClient(), propagateSharedFailures: true)
        : new Tablo\GitHubConnection($sites, new Tablo\HttpClient());
    exit((new Tablo\CheckCommand($sites, $github,
        new Tablo\HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'), $vault))->run($json));
} catch (Throwable $error) {
    if ($json) {
        $reason = match (true) {
            $error instanceof PDOException => 'storage-failed',
            $error instanceof Tablo\SharedKeyFailure => 'key-unavailable',
            $error instanceof Tablo\InstallationException => 'installation-failed',
            default => 'bootstrap-failed',
        };
        $earlyJson('fatal', $reason);
    }
    Tablo\CheckDiagnostics::write("Check failed. Check installation configuration and local storage.\n");
    if ($error instanceof Tablo\InstallationException && $error->getCode() === Tablo\InstallationException::UNSUPPORTED_CURL) {
        Tablo\CheckDiagnostics::write(Tablo\InstallationException::CURL_DIAGNOSTIC . "\n");
    }
    if ($error instanceof Tablo\SharedKeyFailure && $error->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        Tablo\CheckDiagnostics::write(Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
    }
    exit(1);
}
