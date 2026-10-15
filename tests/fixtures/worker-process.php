<?php
declare(strict_types=1);

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
$db = \Tablo\Database::connect();
$worker = new \Tablo\PeriodicWorker($db, new \Tablo\Tests\Support\WorkerHttp(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_WORKER_BASE'))));
exit($worker->run());
