<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointCredential
{
    public static function validateEndpointToken(#[\SensitiveParameter] mixed $token): string
    {
        if (!is_string($token) || ($token !== '' && preg_match('/^[\x21-\x7e]{1,512}$/D', $token) !== 1)) {
            throw ValidationException::field(field: 'endpoint_token', message: 'Токен проверки должен содержать до 512 печатных ASCII-символов без пробелов.');
        }
        return $token;
    }

    public static function endpointTokenInput(#[\SensitiveParameter] array $input): string
    {
        $token = self::validateEndpointToken(array_key_exists('endpoint_token', $input) ? $input['endpoint_token'] : '');
        if ($token !== '' && self::removesEndpointToken($input)) {
            throw ValidationException::field(field: 'endpoint_token', message: 'Выберите замену или удаление токена проверки.');
        }
        return $token;
    }

    public static function removesEndpointToken(#[\SensitiveParameter] array $input): bool
    {
        return FormCheckbox::selected($input['remove_endpoint_token'] ?? null);
    }


}
