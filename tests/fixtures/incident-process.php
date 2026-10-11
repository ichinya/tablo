<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = \Tablo\Database::connect($argv[1]);
$sites = new \Tablo\SiteRepository($db);
$site = $sites->find((int) $argv[2]);
$gate = getenv('TABLO_INCIDENT_GATE');
if ($gate) {
    echo "Ready\n";
    $deadline = microtime(true) + 6;
    while (!is_file($gate) && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
    if (!is_file($gate)) { throw new RuntimeException('Incident fixture gate timeout.'); }
}
$result = match ($argv[3]) {
    'accept' => $sites->storeCheck($site, ['online' => 0, 'checked_at' => '2020-01-01T00:00:00Z']),
    'prune' => (new \Tablo\CheckHistoryRepository($db))->pruneBatch('2021-01-01T00:00:00Z'),
    'delete' => $sites->delete((int) $argv[2]),
    default => throw new RuntimeException('Invalid incident fixture action.'),
};
unset($db, $sites, $site);
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n";
