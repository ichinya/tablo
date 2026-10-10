<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=Tablo\Database::connect($argv[1]);
$claim=(new Tablo\NotificationOutbox($db))->claim((int)$argv[2]);
echo $claim===false?'claimed=0':'claimed=1';
