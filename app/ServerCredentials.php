<?php
declare(strict_types=1);

namespace Tablo;

final class ServerCredentials
{
    /** @return array{user: string, password: string} */
    public static function read(DatabaseEnvironment $environment, string $role): array
    {
        $prefix = match ($role) { 'reader' => 'READ_', 'migrator' => 'MIGRATE_', 'writer', 'control' => '', default => throw new DatabaseFailure('configuration') };
        $user = $environment->read('TABLO_DB_' . $prefix . 'USER');
        $password = $environment->read('TABLO_DB_' . $prefix . 'PASSWORD');
        if (!is_string($user) || $user === '' || strlen($user) > 128 || str_contains($user, "\0")) { throw new DatabaseFailure('configuration'); }
        if (!is_string($password) || str_contains($password, "\0")) { throw new DatabaseFailure('configuration'); }
        return ['user' => $user, 'password' => $password];
    }
}
