<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? null;
if (!is_string($directory) || !is_dir($directory)) { throw new RuntimeException('Owned automatic boundary requires a directory.'); }
$db = \Tablo\Database::connect($directory . '/automatic.sqlite');
$auth = new \Tablo\Auth($db);
$settings = new \Tablo\SettingsRepository($db); $settings->updateIdleTimeout('2');
$secret = \Tablo\Tests\Support\FixtureCredentials::value('fixture-password');
$fingerprint = $auth->setupSession($secret, $secret);
\Tablo\WebSession::start($directory);
$page = new \Tablo\PageResponse($directory);
$epoch = 1000;
\Tablo\WebSession::admit(new \Tablo\SessionAdmission($auth, $settings, $page, \Tablo\SessionResponse::Json, static fn(): int => $epoch), '/login');
\Tablo\WebSession::authenticate($fingerprint);
$observations = [];
foreach ([1010, 1050, 1119, 1120] as $epoch) {
    $refusal = \Tablo\WebSession::admit(new \Tablo\SessionAdmission($auth, $settings, $page, \Tablo\SessionResponse::Json, static fn(): int => $epoch), '/internal-automatic');
    if ($refusal !== null) { $observations[] = ['epoch' => $epoch, 'status' => $refusal->getStatusCode(), 'authenticated' => array_key_exists('authenticated_at', $_SESSION)]; continue; }
    $handler = new \Tablo\WebAction(static function() use ($page): \Phalcon\Http\Response {
        \Tablo\WebSession::recordActivity(); // Even an accidental request cannot override Automatic metadata.
        return $page->json(['ok' => true]);
    }, \Tablo\SessionActivity::Automatic, \Tablo\SessionResponse::Json);
    $response = $handler();
    $observations[] = ['epoch' => $epoch, 'status' => $response->getStatusCode(), 'start' => ($_SESSION['authenticated_at'] ?? null),
        'activity' => ($_SESSION['last_activity_at'] ?? null), 'observed' => ($_SESSION['session_observed_at'] ?? null)];
}
$settings->updateIdleTimeout('720');
$refusal = \Tablo\WebSession::admit(new \Tablo\SessionAdmission($auth, $settings, $page, \Tablo\SessionResponse::Json, static fn(): int => 1121), '/internal-automatic');
session_write_close();
echo json_encode(['observations' => $observations, 'afterIncrease' => $refusal?->getStatusCode()], JSON_THROW_ON_ERROR);
