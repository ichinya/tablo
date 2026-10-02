<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/github-fixture.php';
(new Tablo\Web(new FixtureGitHubHttp()))->run();
