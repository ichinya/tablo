<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

require_once dirname(__DIR__) . '/app/RequiredFile.php';
require_once __DIR__ . '/MagoPinData.php';

/**
 * @phpstan-import-type Asset from MagoPinData
 * @phpstan-type Pin array{asset: string, url: string, archive_sha256: string, binary_sha256: string, member: string, binary: string,
 * directory: string, archive_path: string, binary_path: string, version_output: string, ...}
 */
final class MagoPin
{
    /** @return Pin */
    public static function selected(string $root): array
    {
        $manifest = MagoPinData::checked(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/tools/mago-pin.json')), associative: true,
            flags: JSON_THROW_ON_ERROR));
        $platform = MagoPlatform::selected();
        $pin = $manifest['platforms'][$platform] ?? throw new RuntimeException('Unsupported Mago pin.');
        $directory = $root . '/.ci/mago/' . $manifest['version'] . '/' . $platform;
        $pin['directory'] = $directory;
        $pin['archive_path'] = $directory . '/' . $pin['asset'];
        $pin['binary_path'] = $directory . '/' . $pin['binary'];
        $pin['version_output'] = $manifest['version_output'];
        return $pin;
    }

    public static function digest(string $path, string $expected): void
    {
        $canonical = realpath($path);
        if (!is_file($path) || is_link($path) || $canonical === false
            || str_replace('\\', replace: '/', subject: $canonical) !== str_replace('\\', replace: '/', subject: $path)) {
            throw new RuntimeException('Missing or corrupt pinned Mago file. Run the explicit installer.');
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Missing or corrupt pinned Mago file. Run the explicit installer.');
        }
    }

    /** @param Pin $pin */
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
