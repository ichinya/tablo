<?php
declare(strict_types=1);

namespace Tablo;

use SensitiveParameter;

final class PasswordService
{
    public static function hash(#[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): string
    {
        if (in_array(true, [max(strlen($password), strlen($confirmation)) > 1024, strlen($password) < 12], strict: true)) {
            throw ValidationException::field(field: 'password', message: 'Пароль должен содержать от 12 до 1024 байт.');
        }
        if (str_contains($password . $confirmation, "\0")) {
            throw ValidationException::field(field: 'password', message: 'Пароль не должен содержать NUL.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw ValidationException::field(field: 'confirmation', message: 'Пароли не совпадают.');
        }
        return PasswordHashRuntime::create($password, PasswordHashPolicy::creationOptions());
    }

    public static function verify(#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
    {
        if (in_array(true, [strlen($password) > 1024, str_contains($password, "\0")], strict: true)) { return false; }
        try {
            $algorithm = PasswordHashPolicy::storedAlgorithm($hash);
            if (in_array(true, [$algorithm === null, strlen($password) > (['2y' => 72][$algorithm ?? ''] ?? 1024)], strict: true)) { return false; }
            if (!self::supportsStoredHash($hash)) { throw new PasswordRuntimeFailure(); }
            // Native verification must itself recognize and verify this encoding.
            // Native stored-info equality is additionally required by upgradeOptions.
            return PasswordHashRuntime::verify($password, $hash);
        } catch (\Throwable) { throw new PasswordRuntimeFailure(); }
    }

    /** @internal Only after successful verification of this exact credential snapshot. */
    public static function upgradeVerified(#[SensitiveParameter] string $password, #[SensitiveParameter] string $verifiedHash): ?string
    {
        try {
            // An absent Argon provider permits bcrypt verification, never creation.
            if (!PasswordHashRuntime::registered('argon2id')) {
                if (PasswordHashPolicy::storedAlgorithm($verifiedHash) === '2y') { return null; }
                throw new PasswordRuntimeFailure();
            }
            $options = PasswordHashPolicy::upgradeOptions($verifiedHash);
            return $options === null ? null : PasswordHashRuntime::create($password, $options);
        } catch (\Throwable) { throw new PasswordRuntimeFailure(); }
    }

    public static function supportsStoredHash(#[SensitiveParameter] string $hash): bool
    {
        // These bounded metadata/registration operations perform no password KDF.
        return PasswordHashRuntime::registered(PasswordHashPolicy::storedAlgorithm($hash));
    }
}
