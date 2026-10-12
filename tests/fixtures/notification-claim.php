<?php
declare(strict_types=1);
require dirname(__DIR__,levels: 2).'/vendor/autoload.php';
$db=Tablo\Database::connect(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 1));
$claim=(new Tablo\NotificationOutbox($db))->claim((int)\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($argv), 2));
echo $claim===false?'claimed=0':'claimed=1';
