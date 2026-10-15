<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

final class FixtureGitHubStatus
{
    public static function select(string $path, #[\SensitiveParameter] string $authorization, #[\SensitiveParameter] array $headers): int
    {
        $status = 200;
        switch (true) {
case (str_contains($authorization, 'Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'invalid-fixture-token'))):

            $status = 401;

    break;
case (str_contains($authorization, 'Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'forbidden-fixture-token'))):

            $status = 403;

    break;
case (str_contains($path, '/fixture/private')
            && !in_array('Authorization: Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'), $headers, strict: true)
            && !in_array('Authorization: Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token'), $headers, strict: true)):

            $status = 404;

    break;
}
        return $status;
    }
}
