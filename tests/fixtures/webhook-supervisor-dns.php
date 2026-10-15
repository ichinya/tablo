<?php
declare(strict_types=1);
require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
// Real isolated HTTP API with a deliberately blocking resolver seam; no network call.
$frame=stream_get_contents(STDIN,length: 2049); $lengths=array_values(unpack('N3',substr(\Tablo\Tests\Support\FixtureValue::string($frame),offset: 0,length: 12)));
$payload=\Tablo\JsonRecord::checked(json_decode(substr(\Tablo\Tests\Support\FixtureValue::string($frame),12+\Tablo\Tests\Support\FixtureValue::at($lengths, 0)+\Tablo\Tests\Support\FixtureValue::at($lengths, 1),\Tablo\Tests\Support\FixtureValue::at($lengths, 2)),associative: true,depth: 8,flags: JSON_THROW_ON_ERROR));
$client=new class(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($payload, 'marker_path'))) extends \Tablo\HttpClient {
    public function __construct(private readonly string $marker) { parent::__construct(); }
    protected function resolve(string $host): array { file_put_contents($this->marker,data: 'resolver-entered'); sleep(30); return ['8.8.8.8']; }
};
$client->postBefore('https://receiver.example/hook','{}','fixture:unavailable:1','',\Tablo\MonotonicTime::integer(hrtime(true))+6_000_000_000);
