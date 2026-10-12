<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

final class MagoPin
{
    public static function selected(string $root): array
    {
        $manifest = json_decode(file_get_contents($root . '/tools/mago-pin.json'), associative: true,
            flags: JSON_THROW_ON_ERROR);
        if ($manifest['version'] !== '1.54.0' || $manifest['version_output'] !== 'mago 1.54.0') {
            throw new RuntimeException('Unsupported Mago pin.');
        }
        $platform = MagoPlatform::selected();
        $pin = $manifest['platforms'][$platform];
        $directory = $root . '/.ci/mago/' . $manifest['version'] . '/' . $platform;
        return [...$pin, 'directory' => $directory, 'archive_path' => $directory . '/' . $pin['asset'],
            'binary_path' => $directory . '/' . $pin['binary'], 'version_output' => $manifest['version_output']];
    }

    public static function digest(string $path, string $expected): void
    {
        if (!is_file($path) || is_link($path)
            || str_replace('\\', replace: '/', subject: realpath($path)) !== str_replace('\\', replace: '/', subject: $path)
            || !hash_equals($expected, hash_file('sha256', $path))) {
            throw new RuntimeException('Missing or corrupt pinned Mago file. Run the explicit installer.');
        }
    }

    public static function verify(array $pin, string $root): void
    {
        self::digest($pin['archive_path'], $pin['archive_sha256']);
        self::digest($pin['binary_path'], $pin['binary_sha256']);
        $result = MagoProcess::capture([$pin['binary_path'], '--version'], $root);
        if ($result['exit'] !== 0 || trim($result['stdout']) !== $pin['version_output'] || $result['stderr'] !== '') {
            throw new RuntimeException('Wrong pinned Mago version or failed version transport.');
        }
    }
}
