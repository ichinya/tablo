<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type SiteData from SiteInput
 */
final class SiteIdentityInput
{
    /**
     * @param SiteData $data
     * @param array<string, string> $errors
     */
    public static function normalize(#[\SensitiveParameter] array $input, #[\SensitiveParameter] array &$data, array &$errors): void
    {
        if ($data['name'] === '' || mb_strlen($data['name']) > 80) {
            $errors['name'] = 'Укажите название до 80 символов.';
        }
        SiteUrlInput::validate($data, $errors);
        try {
            $data['repository'] = GitRepositoryName::normalize($data['repository']);
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($data['branch'] === '' || strlen($data['branch']) > 128 || preg_match('/[\s\x00-\x1f]/', $data['branch'])) {
            $errors['branch'] = 'Укажите ветку без пробелов, до 128 символов.';
        }
    }

}
