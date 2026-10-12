<?php
declare(strict_types=1);

namespace Tablo;

final class SiteDisplayInput
{
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        if (!in_array($data['comparison_mode'], ['release', 'branch'], strict: true)) {
            $errors['comparison_mode'] = 'Выберите релиз или HEAD ветки.';
        }
        $data['enabled'] = FormCheckbox::selected($input['enabled'] ?? null) ? 1 : 0;
        $order = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 9999) {
            $errors['sort_order'] = 'Порядок должен быть целым числом от 0 до 9999.';
            return;
        }
        $data['sort_order'] = $order;
    }

}
