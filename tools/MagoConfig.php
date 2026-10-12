<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

/** One complete policy is shared by both offline gates. */
final class MagoConfig
{
    private const EXPECTED = <<<'TOML'
version = "1.54.0"
php-version = "8.2"

[source]
paths = ["app", "bin", "public", "tests", "tools"]
includes = ["vendor"]
excludes = ["storage", "artifacts", ".lekalo"]

[linter]
minimum-fail-level = "note"

[analyzer]
minimum-fail-level = "note"
strict-array-index-existence = true
check-use-statements = true
check-name-casing = true
check-property-initialization = true
TOML;

    public static function verify(string $root): string
    {
        $config = $root . '/mago.toml';
        $contents = is_file($config) ? file_get_contents($config) : false;
        if ($contents === false || str_replace("\r\n", replace: "\n", subject: $contents) !== self::EXPECTED . "\n") {
            throw new RuntimeException('Missing or changed Mago configuration; review the complete lint and analysis scope.');
        }
        return $config;
    }
}
