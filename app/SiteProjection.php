<?php
declare(strict_types=1);

namespace Tablo;

final class SiteProjection
{
    public static function withoutEndpointCredential(#[\SensitiveParameter] ?array $data): ?array
    {
        if ($data === null) { return null; }
        unset($data['endpoint_token'], $data['remove_endpoint_token']);
        return $data;
    }

    public static function publicSite(#[\SensitiveParameter] array $site): array
    {
        $site['has_endpoint_token'] = (bool) ($site['has_endpoint_token'] ?? (is_string($site['endpoint_token'] ?? null) && $site['endpoint_token'] !== ''));
        $site['has_github_token'] ??= (is_string($site['github_token'] ?? null) && $site['github_token'] !== '');
        unset($site['endpoint_token'], $site['remove_endpoint_token'], $site['github_token'],
            $site['remove_github_token'], $site['selected_token_snapshot']);
        return $site;
    }


}
