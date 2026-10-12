<?php
declare(strict_types=1);
namespace Tablo\Tools;

use RuntimeException;

final class ReviewedDependencyCoverage
{
    public static function verify(string $root, array $required): void
    {
        $expected = array_values(array_filter(array_keys($required),
            static fn (string $path): bool => preg_match('~^(app|bin|public|tests|tools)/.+\.php$~D', $path) === 1));
        sort($expected);
        $actual = MagoSource::files($root);
        if ($actual !== $expected) {
            throw new RuntimeException('Unreviewed physical PHP source coverage drift. Review the explicit dependency catalog before verification.');
        }
    }
}
