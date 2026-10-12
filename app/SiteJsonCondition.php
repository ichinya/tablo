<?php
declare(strict_types=1);

namespace Tablo;

final class SiteJsonCondition
{
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        $mode = $input['health_check_mode'] ?? 'http';
        $operator = $input['health_json_operator'] ?? '==';
        $data['health_json_operator'] = is_string($operator) && in_array($operator, JsonField::OPERATORS, strict: true) ? $operator : '==';
        $expected = $input['health_json_expected_value'] ?? '';
        $data['health_json_expected_value'] = is_string($expected) ? $expected : '';
        if ($data['health_path'] !== '' && $mode === 'json') {
            if (!is_string($operator) || !in_array($operator, JsonField::OPERATORS, strict: true)) {
                $errors['health_json_operator'] = 'Выберите допустимое условие JSON-проверки.';
            }
            if (!is_string($expected) || mb_strlen($expected) > JsonField::MAX_EXPECTED_LENGTH) {
                $errors['health_json_expected_value'] = 'Ожидаемое значение — строка до 512 символов.';
                return;
            }
            if (in_array($operator, ['>', '>=', '<', '<='], strict: true)) {
                try { JsonField::number($expected); }
                catch (\InvalidArgumentException $e) { $errors['health_json_expected_value'] = $e->getMessage(); }
            }
        }
    }

}
