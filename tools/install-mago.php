<?php
declare(strict_types=1);

require __DIR__ . '/MagoPlatform.php';
require __DIR__ . '/MagoProcess.php';
require __DIR__ . '/MagoPin.php';
require __DIR__ . '/MagoCache.php';
require __DIR__ . '/MagoDownload.php';
require __DIR__ . '/MagoInstall.php';

try { Tablo\Tools\MagoInstall::run(dirname(__DIR__), array_slice($argv, offset: 1)); }
catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
