<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class EndpointFixture
{
    // Harmless values reconstructed in memory; no raw request credential is logged.
    public static function tokenA(): string { return chr(65) . str_repeat(chr(71), times: 47); }
    public static function tokenB(): string { return chr(66) . str_repeat(chr(72), times: 47); }
    public static function invalidCiphertext(): string { return '0'; }
}
