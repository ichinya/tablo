<?php
declare(strict_types=1);

require __DIR__ . '/MagoPlatform.php';
require __DIR__ . '/MagoProcess.php';
require __DIR__ . '/MagoPin.php';
require __DIR__ . '/MagoConfig.php';
require __DIR__ . '/MagoSource.php';
require __DIR__ . '/MagoAnalyze.php';

try { exit(Tablo\Tools\MagoAnalyze::run(dirname(__DIR__), array_slice($argv, offset: 1))); }
catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
