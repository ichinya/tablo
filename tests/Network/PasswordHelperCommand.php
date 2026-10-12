<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;


final class PasswordHelperCommand
{
    /** @return list<string> */
    public static function forHost(string $shell, string $encoding, string $scenario): array
    {
        return [$shell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                        dirname(__DIR__) . '/fixtures/password-helper-input.ps1', '-Helper',
                        dirname(__DIR__, levels: 2) . '/tools/admin-password.ps1', '-Php', PHP_BINARY,
                        '-HostEncoding', $encoding, '-Scenario', $scenario];
    }
}
