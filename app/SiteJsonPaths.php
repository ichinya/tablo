<?php
declare(strict_types=1);

namespace Tablo;

final class SiteJsonPaths
{
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        $mode = $input['health_check_mode'] ?? 'http';
        $validMode = is_string($mode) && in_array($mode, ['http', 'json'], strict: true);
        if (!$validMode) {
            $errors['health_check_mode'] = 'Выберите HTTP-статус или JSON-поле.';
        }
        if ($validMode) { $data['health_check_mode'] = $mode; }
        foreach (['version_json_path', 'health_json_path'] as $field) {
            $raw = $input[$field] ?? '';
            $data[$field] = is_string($raw) ? trim($raw) : '';
            $active = $field === 'version_json_path' ? $data['version_path'] !== '' : ($data['health_path'] !== '' && $mode === 'json');
            if (!$active || ($field === 'version_json_path' && is_string($raw) && $data[$field] === '')) { continue; }
            try {
                if (!is_string($raw)) { throw new \InvalidArgumentException('Укажите JSON path строкой.'); }
                JsonField::validatePath($data[$field]);
            } catch (\InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
    }

}
