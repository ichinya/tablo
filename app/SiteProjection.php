<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput */
final class SiteProjection
{
    /**
     * @param GitCredentialRecord|null $site
     * @return GitCredentialRecord|null
     */
    public static function gitCredentials(#[\SensitiveParameter] ?array $site): ?array
    {
        if ($site === null) { return null; }
        unset($site['endpoint_token'], $site['remove_endpoint_token']);
        return $site;
    }

    /** @return ($data is null ? null : array) */
    public static function withoutEndpointCredential(#[\SensitiveParameter] ?array $data): ?array
    {
        if ($data === null) { return null; }
        unset($data['endpoint_token'], $data['remove_endpoint_token']);
        return $data;
    }

    /** @return array{has_endpoint_token: bool, has_github_token: mixed, ...} */
    public static function publicSite(#[\SensitiveParameter] array $site): array
    {
        $site['has_endpoint_token'] = ($site['has_endpoint_token'] ?? (is_string($site['endpoint_token'] ?? null) && $site['endpoint_token'] !== '')) ? true : false;
        $site['has_github_token'] ??= (is_string($site['github_token'] ?? null) && $site['github_token'] !== '');
        unset($site['endpoint_token'], $site['remove_endpoint_token'], $site['github_token'],
            $site['remove_github_token'], $site['selected_token_snapshot']);
        return $site;
    }


}
