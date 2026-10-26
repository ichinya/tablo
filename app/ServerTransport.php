<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Transport array{host: string, port: ?int, socket: string} */
final class ServerTransport
{
    /** @return Transport */
    public static function read(DatabaseEnvironment $environment): array
    {
        $socket = $environment->optional('TABLO_DB_SOCKET');
        if (is_string($socket) && $socket !== '') {
            if (!str_starts_with($socket, '/') || strpbrk($socket, characters: ";\r\n\0") !== false) { throw new DatabaseFailure('configuration'); }
            if ($environment->optional('TABLO_DB_HOST') !== false || $environment->optional('TABLO_DB_PORT') !== false) { throw new DatabaseFailure('configuration'); }
            return ['host' => '', 'port' => null, 'socket' => $socket];
        }
        $host = $environment->read('TABLO_DB_HOST');
        $port = $environment->read('TABLO_DB_PORT');
        if (!is_string($host) || strtolower($host) === 'localhost' || preg_match('/\A[a-zA-Z0-9_.:\-]{1,253}\z/D', $host) !== 1) { throw new DatabaseFailure('configuration'); }
        if (!is_string($port) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) !== 1 || (int) $port > 65_535) { throw new DatabaseFailure('configuration'); }
        return ['host' => $host, 'port' => (int) $port, 'socket' => ''];
    }
}
