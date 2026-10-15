<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\TokenVault;

use RuntimeException;
use LogicException;
use Testo\Assert;

final class TokenTamperAssertions
{
    public function __construct(private readonly TokenVault $vault) {}

    public function refuses(string $key): void
    {
            $cipher = $this->vault->encrypt('tamper-fixture');
            $bytes = \Tablo\Tests\Support\FixtureValue::string(base64_decode(substr($cipher, offset: 3), strict: true));
            $bytes[28] = chr(ord(\Tablo\Tests\Support\FixtureValue::string($bytes[28])) ^ 1);
            try {
                $this->vault->decrypt('v1:' . base64_encode(\Tablo\Tests\Support\FixtureValue::string($bytes)));
                throw new LogicException('tampered token accepted');
            } catch (RuntimeException|LogicException $e) { Assert::true(!($e instanceof LogicException), 'tampered token accepted'); }
            unlink($key);
            try {
                $this->vault->decrypt($cipher);
                throw new LogicException('missing key regenerated silently');
            } catch (RuntimeException|LogicException $e) { Assert::true(!($e instanceof LogicException) && !is_file($key), 'missing key regenerated silently'); }

    }
}
