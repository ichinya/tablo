<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

/**
 * @phpstan-type Asset array{asset: string, url: string, archive_sha256: string, binary_sha256: string, member: string, binary: string, ...}
 * @phpstan-type Manifest array{version: '1.54.0', version_output: 'mago 1.54.0', platforms: array<string, Asset>, ...}
 */
final class MagoPinData
{
    /** @return Manifest */
    public static function checked(mixed $value): array
    {
        if (!is_array($value) || ($value['version'] ?? null) !== '1.54.0' || ($value['version_output'] ?? null) !== 'mago 1.54.0'
            || !is_array($value['platforms'] ?? null) || array_is_list($value['platforms'])) { throw new RuntimeException('Unsupported Mago pin.'); }
        $platforms = [];
        foreach (array_keys($value['platforms']) as $platform) {
            if (!is_string($platform)) { throw new RuntimeException('Unsupported Mago pin.'); }
            $platforms[$platform] = self::asset($value['platforms'][$platform] ?? null);
        }
        $value['platforms'] = $platforms;
        /** @var Manifest $value */
        return $value;
    }

    /** @return Asset */
    private static function asset(mixed $value): array
    {
        if (!is_array($value)) { throw new RuntimeException('Unsupported Mago pin.'); }
        foreach (['asset', 'url', 'archive_sha256', 'binary_sha256', 'member', 'binary'] as $field) {
            if (!is_string($value[$field] ?? null)) { throw new RuntimeException('Unsupported Mago pin.'); }
        }
        /** @var Asset $value */
        return $value;
    }
}
