<?php
declare(strict_types=1);

namespace Tablo;

final class VersionResponse
{
    public static function decode(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $jsonPath,
        #[\SensitiveParameter] ?string $endpointToken = null): array
    {
        $document = JsonField::decode($body);
        $json = $document instanceof \stdClass ? (array) $document : [];
        if ($jsonPath === '' && !($document instanceof \stdClass)) {
            throw new \RuntimeException('Ожидается JSON-объект.');
        }
        $version = $jsonPath === '' ? ($json['version'] ?? $json['deployed_version'] ?? null)
            : JsonField::extract($document, $jsonPath);
        $commit = $json['commit'] ?? $json['sha'] ?? $json['deployed_commit'] ?? null;
        $fields = DeploymentFields::validated($version, $commit);
        self::rejectCredentialReflection($fields, $endpointToken);
        return $fields;
    }

    private static function rejectCredentialReflection(#[\SensitiveParameter] array $fields,
        #[\SensitiveParameter] ?string $endpointToken): void
    {
        if ($endpointToken === null || $endpointToken === '') { return; }
        foreach ($fields as $field => $value) {
            // Commits are published in lowercase; compare the credential in that representation too.
            $marker = $field === 'deployed_commit' ? strtolower($endpointToken) : $endpointToken;
            if ($value !== null && str_contains($value, $marker)) { throw new HttpFailure('invalid-response'); }
        }
    }
}
