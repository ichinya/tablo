<?php
declare(strict_types=1);

namespace Tablo;

final class SchemaCheckShape
{

    public static function validate(string $actual, string $wanted, int $version, array $missing): void
    {
        $constraints = SqlSyntax::constraints($wanted);
        if ($version === 0 && $missing !== []) {
            $constraints['checks'] = array_values(array_filter($constraints['checks'],
                static fn (array $tokens): bool => array_intersect($tokens, $missing) === []));
        }
        if (SqlSyntax::constraints($actual) !== $constraints) { SchemaShape::refuse(); }
    }

}
