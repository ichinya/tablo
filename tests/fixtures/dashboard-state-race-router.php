<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

$runtime = getenv('TABLO_TEST_RUNTIME');
$barrier = getenv('TABLO_DASHBOARD_STATE_BARRIER');
if (!is_string($runtime) || !is_string($barrier) || !is_dir($barrier)) { throw new RuntimeException('Owned dashboard race runtime missing.'); }
$db = \Tablo\Database::connect();
\Tablo\WebSession::start($runtime);
$page = new \Tablo\PageResponse($runtime);
$clock = static function () use ($runtime): int {
    $raw = \Tablo\RequiredFile::bytes(file_get_contents(dirname($runtime) . '/runtime-clock'));
    if (preg_match('/^[0-9]+$/D', $raw) !== 1 || strlen($raw) > 10) { throw new RuntimeException('Owned dashboard clock invalid.'); }
    return (int) $raw;
};
$admission = new \Tablo\SessionAdmission(new \Tablo\Auth($db), new \Tablo\SettingsRepository($db), $page, \Tablo\SessionResponse::Json, $clock);
$refusal = \Tablo\WebSession::admit($admission, '/dashboard/state');
if ($refusal !== null) { $refusal->send(); return; }
// This connection-local SELECT barrier changes no main schema or saved data and adds no production hook.
$db->sqliteCreateFunction('dashboard_state_barrier', static function (string $name) use ($barrier): string {
    file_put_contents($barrier . '/entered', data: 'entered');
    $deadline = microtime(true) + 6;
    do {
        clearstatcache();
        if (is_file($barrier . '/release')) { return $name; }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Owned dashboard read barrier timed out.');
}, 1);
$db->exec('CREATE TEMP VIEW sites AS SELECT id, dashboard_state_barrier(name) AS name, url, repository,
    branch, enabled, sort_order, online, checked_at, deployed_version, latest_release, deployed_commit,
    latest_commit, comparison_mode, last_error, health_error_code, health_http_status, open_issues,
    open_prs, response_time_ms, version_path FROM main.sites');
$handler = new \Tablo\WebAction((new \Tablo\DashboardStatePage(new \Tablo\SavedDashboardRepository($db), $page))->state(...),
    \Tablo\SessionActivity::Automatic, \Tablo\SessionResponse::Json);
$handler()->send();
