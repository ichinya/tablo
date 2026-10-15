<?php
declare(strict_types=1);

namespace Tablo;

final class SiteTextInput
{
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        foreach (['name', 'url', 'repository', 'branch', 'health_path', 'version_path', 'comparison_mode'] as $field) {
            $data[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        }
    }

}
