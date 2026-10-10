<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/http-client.php';
require dirname(__DIR__,2).'/app/webhook-address.php';
require dirname(__DIR__,2).'/app/webhook-failure.php';
require dirname(__DIR__,2).'/app/validation-exception.php';
require dirname(__DIR__,2).'/app/http-failure.php';
// Real isolated HTTP API with a deliberately blocking resolver seam; no network call.
stream_get_contents(STDIN,2049);
$client=new class extends \Tablo\HttpClient {
    protected function resolve(string $host): array { sleep(30); return ['8.8.8.8']; }
};
$client->postBefore('https://receiver.example/hook','{}','fixture:unavailable:1','',hrtime(true)+6000000000);
