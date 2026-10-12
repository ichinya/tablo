<?php
declare(strict_types=1);

namespace Tablo;

final class SiteCredentialInput
{
    public static function prepare(#[\SensitiveParameter] TokenVault $vault, #[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $existing, ?int $selected, #[\SensitiveParameter] string $token): array
    {
        $endpointToken = EndpointCredential::endpointTokenInput($input);
        $credentials['endpoint_token'] = self::endpoint($vault,$input,$existing,$endpointToken);
        $credentials['github_token'] = self::git($vault,$input,$existing,$selected,$token);
        return $credentials;
    }
    private static function endpoint(#[\SensitiveParameter] TokenVault $vault,#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $existing,#[\SensitiveParameter] string $token): ?string
    {
        if ($token !== '') { return $vault->encrypt($token); }
        if (EndpointCredential::removesEndpointToken($input)) { return null; }
        return $existing['endpoint_token'] ?? null;
    }
    private static function git(#[\SensitiveParameter] TokenVault $vault,#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $existing,?int $selected,#[\SensitiveParameter] string $token): ?string
    {
        if ($selected !== null) { return null; }
        if ($token !== '') { return $vault->encrypt($token); }
        if (FormCheckbox::selected($input['remove_github_token'] ?? null)) { return null; }
        return $existing['github_token'] ?? null;
    }
}
