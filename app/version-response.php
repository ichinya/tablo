<?php
declare(strict_types=1);

namespace Tablo;

final class VersionResponse
{
    public static function decode(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $jsonPath): array
    {
        $document = JsonField::decode($body);
        $json = $document instanceof \stdClass ? (array) $document : [];
        if ($jsonPath === '' && !($document instanceof \stdClass)) {
            throw new \RuntimeException('Ожидается JSON-объект.');
        }
        $version = $jsonPath === '' ? ($json['version'] ?? $json['deployed_version'] ?? null)
            : JsonField::extract($document, $jsonPath);
        $commit = $json['commit'] ?? $json['sha'] ?? $json['deployed_commit'] ?? null;
        return DeploymentFields::validated($version, $commit);
    }
}
