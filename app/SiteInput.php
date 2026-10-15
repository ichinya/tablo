<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type SiteData array{
 *   name: string, url: string, repository: string, branch: string, health_path: string, version_path: string,
 *   version_json_path: string, health_check_mode: string, health_json_path: string, health_json_operator: string,
 *   health_json_expected_value: string, comparison_mode: string, enabled: int, sort_order: int
 * }
 */
final class SiteInput
{
    /** @return SiteData */
    public static function defaults(): array
    {
        return ['name' => '', 'url' => '', 'repository' => '', 'branch' => 'main', 'health_path' => '/up',
            'version_path' => '', 'version_json_path' => '', 'health_check_mode' => 'http',
            'health_json_path' => '', 'health_json_operator' => '==', 'health_json_expected_value' => '',
            'comparison_mode' => 'release', 'enabled' => 1, 'sort_order' => 0];
    }


    public static function normalizeRepository(#[\SensitiveParameter] string $repository): string
    {
        return GitRepositoryName::normalize($repository);
    }
    /** @return SiteData */
    public static function normalize(#[\SensitiveParameter] array $input): array
    {
        EndpointCredential::endpointTokenInput($input);
        $data = self::defaults();
        $errors = [];
        foreach ([SiteTextInput::normalize(...), SiteIdentityInput::normalize(...), SiteEndpointPaths::normalize(...),
            SiteJsonPaths::normalize(...), SiteJsonCondition::normalize(...), SiteDisplayInput::normalize(...)] as $normalize) {
            $normalize($input, $data, $errors);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $data;
    }
}
