<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = \Tablo\Database::connect();
$worker = new \Tablo\PeriodicWorker($db, new \Tablo\Tests\Support\WorkerHttp(getenv('TABLO_WORKER_BASE')));
exit($worker->run());
