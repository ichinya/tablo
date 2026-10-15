<?php
declare(strict_types=1);
namespace Tablo\Tools;

use RuntimeException;

final class ReviewedDependencyEntry
{
    public static function verify(mixed $entry, string $path, array $metadata, string $root): void
    {
        self::shape($entry, $path);
        if ($entry['path'] !== $path || $entry['owner'] !== $metadata['owner'] || $entry['consumers'] !== $metadata['consumers']) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        if (!hash_equals($entry['fingerprint'], \dependency_hash($root, $path))) {
            throw new RuntimeException('Unreviewed dependency source drift: ' . $path . '. Review source and explicitly capture bindings.');
        }
    }

    private static function shape(mixed $entry, string $path): void
    {
        if (!is_array($entry) || array_keys($entry) !== ['path', 'owner', 'consumers', 'fingerprint']) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
        if (!is_string($entry['fingerprint']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $entry['fingerprint'])) {
            throw new RuntimeException('Invalid project-local reviewed dependency metadata: ' . $path);
        }
    }
}
