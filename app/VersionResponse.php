<?php
declare(strict_types=1);

namespace Tablo;

final class VersionResponse
{
    /** @return array{deployed_version: ?string, deployed_commit: ?string} */
    public static function decode(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $jsonPath,
        #[\SensitiveParameter] ?string $endpointToken = null): array
    {
        $fields = self::fields(JsonField::decode($body), $jsonPath);
        self::rejectCredentialReflection($fields, $endpointToken);
        return $fields;
    }

    /** @return array{deployed_version: ?string, deployed_commit: ?string} */
    private static function fields(#[\SensitiveParameter] mixed $document, #[\SensitiveParameter] string $jsonPath): array
    {
        $json = $document instanceof \stdClass ? (array) $document : [];
        if ($jsonPath === '' && !($document instanceof \stdClass)) {
            throw new \RuntimeException('Ожидается JSON-объект.');
        }
        return DeploymentFields::validated( $jsonPath === '' ? ($json['version'] ?? $json['deployed_version'] ?? null)
            : JsonField::extract($document, $jsonPath),
            $json['commit'] ?? $json['sha'] ?? $json['deployed_commit'] ?? null);
    }

    /** @param array{deployed_version: ?string, deployed_commit: ?string} $fields */
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
