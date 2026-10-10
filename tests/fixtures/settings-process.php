<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = \Tablo\Database::connect();
$settings = new \Tablo\SettingsRepository($db);
for ($i = 0; $i < 20; ++$i) { $settings->updateInterval($argv[1]); }
echo "Settings committed.\n";
