<?php
declare(strict_types=1);
namespace Tablo\Tools;

use RuntimeException;

/**
 * @phpstan-import-type Metadata from ReviewedDependencyCatalog
 * @phpstan-type Entry array{path: string, owner: string, consumers: list<string>, fingerprint: string}
 */
final class ReviewedDependencyEntry
{
    /** @param Metadata $metadata */
    public static function verify(mixed $entry, string $path, array $metadata, string $root): void
    {
        $entry = self::shape($entry, $path);
        if ($entry['path'] !== $path || $entry['owner'] !== $metadata['owner'] || !self::sameConsumers($entry['consumers'], $metadata['consumers'])) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        if (!hash_equals($entry['fingerprint'], \dependency_hash($root, $path))) {
            throw new RuntimeException('Unreviewed dependency source drift: ' . $path . '. Review source and explicitly capture bindings.');
        }
    }

    /**
     * @param list<string> $actual
     * @param list<string> $expected
     */
    private static function sameConsumers(array $actual, array $expected): bool { return $actual === $expected; }

    /** @return Entry */
    private static function shape(mixed $entry, string $path): array
    {
        if (!is_array($entry) || array_keys($entry) !== ['path', 'owner', 'consumers', 'fingerprint']) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        if (!is_string($entry['path'] ?? null) || !is_string($entry['fingerprint'] ?? null) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $entry['fingerprint'])) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        ReviewedDependencyCatalog::metadata($entry, $path);
        /** @var Entry $entry */
        return $entry;
    }
}
