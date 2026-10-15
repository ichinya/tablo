<?php
declare(strict_types=1);

namespace Tablo;

final class SavedGitTokenInput
{
    public static function parse(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $existing): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $provider = is_string($input['provider'] ?? null) ? $input['provider'] : '';
        GitProviders::requireSupported($provider);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::field(field: 'name', message: 'Укажите название токена до 80 символов.');
        }
        try {
            $value = TokenInput::validateToken($input['token'] ?? '');
        } catch (ValidationException $e) {
            throw new ValidationException(['token' => $e->getMessage()]);
        }
        if ($value === '' && $existing === null) {
            throw ValidationException::field(field: 'token', message: 'Введите токен доступа.');
        }
        return [$name,$provider,$value];
    }
}
