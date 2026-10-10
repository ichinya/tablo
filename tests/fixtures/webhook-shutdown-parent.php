<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
$payload=stream_get_contents(STDIN,1025); $data=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
(new \Tablo\WebhookSupervisor(__DIR__.'/webhook-shutdown-child.php'))->attempt('https://receiver.example/hook','',$payload,
    static function()use($data):bool{clearstatcache();if(is_file($data['marker_path'])){exit(0);}return false;});
exit(3);
