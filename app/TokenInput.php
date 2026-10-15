<?php
declare(strict_types=1);

namespace Tablo;

final class TokenInput
{
    public static function validateToken(#[\SensitiveParameter] mixed $token): string
    {
        if (!is_string($token) || ($token !== '' && !preg_match('/^[\x21-\x7e]{1,512}$/D', $token))) {
            throw ValidationException::field(field: 'github_token', message: 'Токен должен содержать до 512 печатных символов без пробелов.');
        }
        return $token;
    }


}
