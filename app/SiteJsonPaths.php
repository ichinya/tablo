<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type SiteData from SiteInput
 */
final class SiteJsonPaths
{
    /**
     * @param SiteData $data
     * @param array<string, string> $errors
     */
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        self::paths($input, $input['health_check_mode'] ?? 'http', $data, $errors);
    }

    /**
     * @param SiteData $data
     * @param array<string, string> $errors
     */
    private static function paths(#[\SensitiveParameter] array $input, #[\SensitiveParameter] mixed $mode, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        $validMode = is_string($mode) && in_array($mode, ['http', 'json'], strict: true);
        if (!$validMode) {
            $errors['health_check_mode'] = 'Выберите HTTP-статус или JSON-поле.';
        }
        if ($validMode) { $data['health_check_mode'] = $mode; }
        foreach (['version_json_path', 'health_json_path'] as $field) {
            self::path($input[$field] ?? '', $field, $mode, $data, $errors);
        }
    }

    /**
     * @param 'version_json_path'|'health_json_path' $field
     * @param SiteData $data
     * @param array<string, string> $errors
     */
    private static function path(#[\SensitiveParameter] mixed $raw, string $field, #[\SensitiveParameter] mixed $mode,
        #[\SensitiveParameter] array &$data, array &$errors): void
    {
            $data[$field] = is_string($raw) ? trim($raw) : '';
            $active = $field === 'version_json_path' ? $data['version_path'] !== '' : ($data['health_path'] !== '' && $mode === 'json');
            if (!$active || ($field === 'version_json_path' && is_string($raw) && $data[$field] === '')) { return; }
            try {
                if (!is_string($raw)) { throw new \InvalidArgumentException('Укажите JSON path строкой.'); }
                JsonField::validatePath($data[$field]);
            } catch (\InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
    }

}
