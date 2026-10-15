<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class KeyCorruptionFixture
{
    public static function apply(string $kind, string $key): void
    {
                rename($key, $key . '.backup');
                if ($kind === 'corrupt') { file_put_contents($key, data: 'invalid'); }
                if ($kind === 'empty') { touch($key); }
                // A directory cannot be read as a 32-byte vault file on either platform.
                if ($kind === 'unreadable') { mkdir($key); }
                if ($kind === 'denied') {
                    copy($key . '.backup', $key);
                    chmod($key, permissions: 0o000);
                    Assert::false(is_readable($key), 'Linux non-root control uses an actual unreadable key file');
                }

    }
}
