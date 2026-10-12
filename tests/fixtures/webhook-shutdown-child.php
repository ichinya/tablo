<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureValue.php';
require_once dirname(__DIR__, levels: 2) . '/app/JsonRecord.php';
$frame=stream_get_contents(STDIN,length: 2049); $lengths=array_values(unpack('N3',substr(\Tablo\Tests\Support\FixtureValue::string($frame),offset: 0,length: 12)));
$payload=\Tablo\JsonRecord::checked(json_decode(substr(\Tablo\Tests\Support\FixtureValue::string($frame),12+\Tablo\Tests\Support\FixtureValue::at($lengths, 0)+\Tablo\Tests\Support\FixtureValue::at($lengths, 1),\Tablo\Tests\Support\FixtureValue::at($lengths, 2)),associative: true,depth: 8,flags: JSON_THROW_ON_ERROR));
$errno = 0; $message = '';
$socket=stream_socket_server('tcp://127.0.0.1:'.\Tablo\Tests\Support\FixtureValue::integer(\Tablo\Tests\Support\FixtureValue::at($payload, 'port')),$errno,$message);
if($socket===false){exit(2);}
file_put_contents(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($payload, 'marker_path')),data: 'owned-listener-ready');
sleep(30);
