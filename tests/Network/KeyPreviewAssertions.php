<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class KeyPreviewAssertions
{
    public static function refused(array $result, #[\SensitiveParameter] string $secret, string $key): void
    {
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($result, 'exit_code'), 0);
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($result, 'stderr'), '');
                Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($result, 'stdout')), $secret));
                $observed = \Tablo\JsonRecord::checked(json_decode(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($result, 'stdout')), associative: true, depth: 16, flags: JSON_THROW_ON_ERROR));
                Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked($observed), 'outcome'), 'key-unavailable');
                Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\JsonRecord::checked($observed), 'core'), 0);
                Assert::false(\Tablo\Tests\Support\FixtureJson::boolean($observed, 'traceHasToken'));
                Assert::false(file_exists($key));

    }
}
