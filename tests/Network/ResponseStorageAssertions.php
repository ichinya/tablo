<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TestServer;
use Testo\Assert;

final class ResponseStorageAssertions
{
    public function __construct(private readonly TestServer $server) {}

    /** @param list<string> $privateValues */
    public function private(array $rows, string $path, #[\SensitiveParameter] array $privateValues): void
    {
                    foreach ($privateValues as $private) {
                        Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($rows)), \Tablo\Tests\Support\FixtureValue::string($private)));
                        Assert::false(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($path)), \Tablo\Tests\Support\FixtureValue::string($private)));
                        Assert::false(str_contains($this->server->diagnostics(), \Tablo\Tests\Support\FixtureValue::string($private)));
                    }

    }
}
