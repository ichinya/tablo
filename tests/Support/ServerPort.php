<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class ServerPort
{
    public static function allocate(string $socketHost): int
    {
        $socket = stream_socket_server('tcp://' . $socketHost . ':0', $errno, $error);
        if ($socket === false) { throw new RuntimeException('Cannot allocate test port: ' . $error); }
        $port = (int) substr(strrchr(stream_socket_get_name($socket, remote: false), needle: ':'), offset: 1);
        fclose($socket);
        return $port;
    }
}
