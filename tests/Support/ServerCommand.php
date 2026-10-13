<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class ServerCommand
{
    /** @return list<string> */
    public static function create(string $router, ?string $documentRoot, string $address, string $errorLog): array
    {
        $option = 'error_log="' . str_replace('\\', replace: '/', subject: $errorLog) . '"';
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', $option, '-S', $address];
        if ($documentRoot !== null) { array_push($command, '-t', $documentRoot); }
        $command[] = $router;
        return $command;
    }
}
