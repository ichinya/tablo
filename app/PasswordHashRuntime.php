<?php
declare(strict_types=1);

namespace Tablo;

/** Native password operations; failures never retain native exception arguments. */
final class PasswordHashRuntime
{
    public static function registered(?string $algorithm): bool
    {
        $name = ['argon2id' => 'PASSWORD_ARGON2ID', 'argon2i' => 'PASSWORD_ARGON2I', '2y' => 'PASSWORD_BCRYPT'][$algorithm ?? ''] ?? '';
        return [defined($name) ? constant($name) : null, in_array($algorithm, password_algos(), strict: true)] === [$algorithm, true];
    }

    public static function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        try { return password_verify($password, $hash); }
        catch (\Throwable) { throw new PasswordRuntimeFailure(); }
    }

    /** @param array{memory_cost: int, time_cost: int, threads: int} $options */
    public static function create(#[\SensitiveParameter] string $password, #[\SensitiveParameter] array $options): string
    {
        try {
            if (!self::registered('argon2id')) { throw new PasswordRuntimeFailure(); }
            $hash = password_hash($password, 'argon2id', $options);
            $header = '$argon2id$v=19$m=' . $options['memory_cost'] . ',t=' . $options['time_cost'] . ',p=' . $options['threads'] . '$';
            /** @var array{algo: ?string, options: array{memory_cost?: int, time_cost?: int, threads?: int}, ...} $info */
            $info = password_get_info($hash);
            if ([PasswordHashPolicy::storedAlgorithm($hash), $info['algo'], $info['options'], str_starts_with($hash, $header), password_verify($password, $hash)]
                !== ['argon2id', 'argon2id', $options, true, true]) { throw new PasswordRuntimeFailure(); }
            return $hash;
        } catch (\Throwable) { throw new PasswordRuntimeFailure(); }
    }
}
