<?php
declare(strict_types=1);

namespace Tablo;

final class SiteFormPrivacy
{
    public static function sanitize(array &$data,array $errors): void
    {
            // Rejected userinfo/query may contain credentials. Valid endpoint query syntax
            // is still accepted by normalize; this only limits error-page repopulation.
            foreach (['url', 'health_path', 'version_path'] as $field) {
                $sensitiveQuery = preg_match('/[?&](?:token|access_token|api_key|password|secret|authorization|key)=/i', rawurldecode($data[$field])) === 1;
                if (($field === 'url' && strpbrk($data[$field], characters: '@?#') !== false)
                    || ($field !== 'url' && ((($errors[$field] ?? null) !== null) || $sensitiveQuery))) { $data[$field] = ''; }
            }
    }
}
