<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TestServer;
use Testo\Assert;

final class ResponseStorageAssertions
{
    public function __construct(private readonly TestServer $server) {}

    public function private(array $rows, string $path, #[\SensitiveParameter] array $privateValues): void
    {
                    foreach ($privateValues as $private) {
                        Assert::false(str_contains(json_encode($rows), $private));
                        Assert::false(str_contains(file_get_contents($path), $private));
                        Assert::false(str_contains($this->server->diagnostics(), $private));
                    }

    }
}
