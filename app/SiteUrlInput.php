<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type SiteData from SiteInput
 */
final class SiteUrlInput
{
    /**
     * @param SiteData $data
     * @param array<string, string> $errors
     */
    public static function validate(#[\SensitiveParameter] array $data, array &$errors): void
    {
        $parts = parse_url($data['url']);
        if (strlen($data['url']) > 500 || !filter_var($data['url'], FILTER_VALIDATE_URL)
            || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], strict: true)
            || (($parts['user'] ?? null) !== null) || (($parts['pass'] ?? null) !== null) || (($parts['query'] ?? null) !== null) || (($parts['fragment'] ?? null) !== null)) {
            $errors['url'] = 'Нужен HTTP(S) URL без логина, пароля, query и fragment.';
        }
    }

}
