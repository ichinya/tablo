<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;

final class VaultKeyAssertions
{
    public function __construct(private readonly TemporaryDirectory $directory) {}

    public function unchanged(string $key, string $wrong, string $originalBytes, string $wrongBytes): void
    {
                Assert::same(file_get_contents($key), $originalBytes);
                Assert::same(file_get_contents($wrong), $wrongBytes);
                Assert::false(is_file($this->directory->path . '/github-token.key'));

    }
}
