<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TestServer;
use Testo\Assert;

final class KeyIntegrityAssertions
{
    public function __construct(private readonly TestServer $server) {}

    public function retained(string $kind, string $key, #[\SensitiveParameter] string $secret): void
    {
                if ($kind === 'missing') { Assert::false(file_exists($key)); }
                if ($kind === 'corrupt') { Assert::same(file_get_contents($key), 'invalid'); }
                if ($kind === 'empty') { Assert::same(filesize($key), 0); }
                if ($kind === 'unreadable') { Assert::true(is_dir($key)); }
                if ($kind === 'denied') {
                    chmod($key, permissions: 0o600);
                    Assert::same(hash_file('sha256', $key), hash_file('sha256', $key . '.backup'));
                }
                Assert::false(str_contains($this->server->diagnostics(), $secret));

    }
}
