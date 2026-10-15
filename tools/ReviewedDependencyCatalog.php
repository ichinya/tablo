<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

/** @phpstan-type Metadata array{owner: string, consumers: list<string>, ...} */
final class ReviewedDependencyCatalog
{
    /** @return array<string, Metadata> */
    public static function checked(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) { throw new RuntimeException('Invalid explicit reviewed dependency catalog.'); }
        $result = [];
        foreach (array_keys($value) as $path) {
            if (!is_string($path)) { throw new RuntimeException('Invalid explicit reviewed dependency catalog.'); }
            $result[$path] = self::metadata($value[$path] ?? null, $path);
        }
        return $result;
    }

    /** @return Metadata */
    public static function metadata(mixed $value, string $path): array
    {
        if (!is_array($value) || !is_string($value['owner'] ?? null) || !is_array($value['consumers'] ?? null)
            || !array_is_list($value['consumers']) || array_filter($value['consumers'], static fn (mixed $consumer): bool => !is_string($consumer)) !== []) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        /** @var Metadata $value */
        return $value;
    }
}
