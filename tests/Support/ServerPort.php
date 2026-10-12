<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class ServerPort
{
    public static function allocate(string $socketHost): int
    {
        $errno = 0; $error = '';
        $socket = stream_socket_server('tcp://' . $socketHost . ':0', $errno, $error);
        if ($socket === false) { throw new RuntimeException('Cannot allocate test port: ' . (string) $error); }
        $port = (int) substr(\Tablo\Tests\Support\FixtureValue::string(strrchr(\Tablo\Tests\Support\FixtureValue::string(stream_socket_get_name($socket, remote: false)), needle: ':')), offset: 1);
        fclose($socket);
        return $port;
    }
}
