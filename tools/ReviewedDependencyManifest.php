<?php
declare(strict_types=1);
namespace Tablo\Tools;

use RuntimeException;

final class ReviewedDependencyManifest
{
    public static function read(string $root, int $required): array
    {
        $path = $root . '/contracts/reviewed-dependencies.json';
        if (!is_file($path)) { throw new RuntimeException('Missing project-local reviewed dependencies. Explicit capture required.'); }
        try { $manifest = json_decode(file_get_contents($path), associative: true, depth: 32, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Malformed project-local reviewed dependencies.'); }
        if (!is_array($manifest) || array_keys($manifest) !== ['schema', 'dependencies']
            || $manifest['schema'] !== 'tablo/reviewed-dependencies/v1' || !is_array($manifest['dependencies'])) {
            throw new RuntimeException('Invalid project-local reviewed dependencies manifest.');
        }
        $entries = $manifest['dependencies'];
        if (!array_is_list($entries) || count($entries) !== $required) {
            throw new RuntimeException('Invalid project-local reviewed dependencies manifest.');
        }
        return $entries;
    }
}
