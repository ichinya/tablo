<?php
declare(strict_types=1);

namespace Tablo;

final class SiteFormPrivacy
{
    /**
     * @param array{url: string, health_path: string, version_path: string, ...} $data
     * @param array<string, string> $errors
     */
    public static function sanitize(array &$data,array $errors): void
    {
            // Rejected userinfo/query may contain credentials. Valid endpoint query syntax
            // is still accepted by normalize; this only limits error-page repopulation.
            foreach (['url' => $data['url'], 'health_path' => $data['health_path'], 'version_path' => $data['version_path']] as $field => $value) {
                $sensitiveQuery = preg_match('/[?&](?:token|access_token|api_key|password|secret|authorization|key)=/i', rawurldecode($value)) === 1;
                if (($field === 'url' && strpbrk($value, characters: '@?#') !== false)
                    || ($field !== 'url' && ((($errors[$field] ?? null) !== null) || $sensitiveQuery))) { $data[$field] = ''; }
            }
    }
}
