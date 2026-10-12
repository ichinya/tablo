<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type SiteData from SiteInput
 */
final class SiteEndpointPaths
{
    /**
     * @param SiteData $data
     * @param array<string, string> $errors
     */
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        foreach (['health_path' => $data['health_path'], 'version_path' => $data['version_path']] as $field => $path) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                $errors[$field] = 'Укажите путь строкой.';
            }
            if ($path === '') { continue; }
            if (!str_starts_with($path, '/') || str_starts_with($path, '//') || strlen($path) > 300
                || preg_match('~[\s\\\\\x00-\x1f#]~', $path)) {
                $errors[$field] = 'Укажите путь на этом сайте, например /up.';
            }
        }
    }

}
