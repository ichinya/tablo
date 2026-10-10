<?php
declare(strict_types=1);

namespace Tablo;

use SensitiveParameter;

final class PasswordService
{
    public static function hash(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): string
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new ValidationException(['password' => 'Пароль должен содержать от 12 до 72 байт.']);
        }
        if (str_contains($password, "\0") || str_contains($confirmation, "\0")) {
            throw new ValidationException(['password' => 'Пароль не должен содержать NUL.']);
        }
        if (!hash_equals($password, $confirmation)) {
            throw new ValidationException(['confirmation' => 'Пароли не совпадают.']);
        }
        try { return password_hash($password, PASSWORD_DEFAULT); }
        catch (\Throwable) { throw new \RuntimeException('Password hashing failed.'); }
    }
}
