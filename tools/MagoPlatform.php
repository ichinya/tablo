<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

final class MagoPlatform
{
    public static function selected(): string
    {
        $machine = strtolower(php_uname(mode: 'm'));
        if (!in_array($machine, ['amd64', 'x86_64'], strict: true)) { throw new RuntimeException('Unsupported Mago architecture.'); }
        if (PHP_OS_FAMILY === 'Windows') { return 'windows-x64'; }
        if (PHP_OS_FAMILY === 'Linux' && (is_file('/lib64/ld-linux-x86-64.so.2')
            || is_file('/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2'))) { return 'linux-x64-gnu'; }
        throw new RuntimeException('Unsupported Mago platform; x64 Windows or glibc Linux required.');
    }
}
