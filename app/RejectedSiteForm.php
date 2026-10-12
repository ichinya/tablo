<?php
declare(strict_types=1);

namespace Tablo;

final class RejectedSiteForm
{
    public static function data(#[\SensitiveParameter] ?array $existing, ?int $id): array
    {
            $data = SiteInput::defaults();
            foreach ($data as $key => $value) {
                if (!(is_string($_POST[$key] ?? null))) { continue; }

                    $data[$key] = $_POST[$key];

            }
            $data['enabled'] = (($_POST['enabled'] ?? null) !== null) && is_string($_POST['enabled']) ? 1 : 0;
            $data['id'] = $id;
            $data['has_github_token'] = !!($existing['github_token'] ?? null);
            $data['has_endpoint_token'] = SiteProjection::publicSite($existing ?? [])['has_endpoint_token'];
            $data['git_token_id'] = is_string($_POST['git_token_id'] ?? null) ? $_POST['git_token_id'] : ($existing['git_token_id'] ?? '');
        return $data;
    }

}
