<?php
declare(strict_types=1);

namespace Tablo;

final class ExistingDatabaseUri
{
    public static function validateExistingUri(#[\SensitiveParameter] string $path): void
    {
        if (!str_starts_with($path, 'file:')) { return; }
        $uri = explode('#', $path, limit: 2)[0];
        if (str_contains(rawurldecode($uri), "\0")) {
            throw new InstallationException('Installation unavailable.');
        }
        $query = explode('?', $uri, limit: 2)[1] ?? '';
        foreach (explode('&', $query) as $parameter) {
            [$name, $value] = array_pad(explode('=', $parameter, limit: 2), length: 2, value: '');
            $name = rawurldecode($name);
            $value = rawurldecode($value);
            // SQLite decodes URI keys/values, and these options override useful
            // READWRITE/locking behavior. Explicit VFS selection is unreviewed;
            // use SQLite's native default. Keep the original URI for actual PDO.
            if (str_contains($name . $value, "\0")
                || $name === 'vfs'
                || ($name === 'mode' && in_array($value, ['ro', 'memory'], strict: true))
                || (in_array($name, ['immutable', 'nolock'], strict: true)
                    && !in_array(strtolower($value), ['', '0', 'false', 'off', 'no'], strict: true))) {
                throw new InstallationException('Installation unavailable.');
            }
        }
    }

}
