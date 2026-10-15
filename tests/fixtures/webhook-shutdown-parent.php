<?php
declare(strict_types=1);
require dirname(__DIR__,levels: 2).'/vendor/autoload.php';
$payload=stream_get_contents(STDIN,length: 1025); $data=\Tablo\JsonRecord::checked(json_decode(\Tablo\Tests\Support\FixtureValue::string($payload),associative: true,depth: 8,flags: JSON_THROW_ON_ERROR));
(new \Tablo\WebhookSupervisor(__DIR__.'/webhook-shutdown-child.php'))->attempt('https://receiver.example/hook','',\Tablo\Tests\Support\FixtureValue::string($payload),
    static function()use($data):bool{clearstatcache();if(is_file(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($data, 'marker_path')))){exit(0);}return false;});
exit(3);
