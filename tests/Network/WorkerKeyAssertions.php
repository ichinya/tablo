<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class WorkerKeyAssertions
{
    public static function unchanged(string $kind, string $key, string $hash): void
    {
                switch (true) {
case (in_array($kind, ['missing', 'live'], strict: true)):
 Assert::false(file_exists($key), 'no replacement/fallback key');
    break;
case ($kind === 'live-replacement'):

                    Assert::same(filesize($key), 32);
                    Assert::false(hash_equals(hash_file('sha256', $key), $hash), 'changed valid-length key is never accepted');

    break;
default:
 Assert::same(file_get_contents($key), $kind === 'corrupt' ? 'invalid' : '');
    break;
}

    }
}
