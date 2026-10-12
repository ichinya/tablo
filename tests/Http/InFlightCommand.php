<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;


final class InFlightCommand
{
    /** @return list<string> */
    public static function forMode(string $mode, \Tablo\Tests\Support\WebFixture $web, int $id, string $csrf): array
    {
                $root = dirname(__DIR__, levels: 2);
                return $mode === 'manual' ? [PHP_BINARY, '-r',
                    '$c=curl_init($argv[1]);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(["_csrf"=>$argv[3]]),CURLOPT_COOKIEFILE=>$argv[2],CURLOPT_PROXY=>"",CURLOPT_TIMEOUT=>10]);curl_exec($c);exit(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===303?0:1);',
                    $web->server->base . '/sites/' . $id . '/check', $web->directory->path . '/cookies', $csrf]
                    : (match (true) { ($mode === 'cli') => [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/FixtureCliGitHubConnection.php', $root . '/bin/check.php'], default => [PHP_BINARY, $root . '/tests/fixtures/worker-process.php'] });
    }

}
