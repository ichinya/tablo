<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class KeyPreviewAssertions
{
    public static function refused(array $result, #[\SensitiveParameter] string $secret, string $key): void
    {
                Assert::same($result['exit_code'], 0);
                Assert::same($result['stderr'], '');
                Assert::false(str_contains($result['stdout'], $secret));
                $observed = json_decode($result['stdout'], associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
                Assert::same($observed['outcome'], 'key-unavailable');
                Assert::same($observed['core'], 0);
                Assert::false($observed['traceHasToken']);
                Assert::false(file_exists($key));

    }
}
