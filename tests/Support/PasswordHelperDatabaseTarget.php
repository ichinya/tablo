<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use RuntimeException;

/** Optional private host output; default CI keeps this separate from the upload sink. */
final class PasswordHelperDatabaseTarget
{
    public static function create(): string
    {
        $root = getenv('TABLO_PRIVATE_SYNTHETIC_DATABASE_DIRECTORY');
        if ($root === false || $root === '') { return (new TemporaryDirectory('tablo-private-synthetic-db-'))->path; }
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($root) || is_link($root)) { throw new RuntimeException('Required private synthetic database output is unavailable'); }
        $public = getenv('TABLO_HELPER_DIAGNOSTICS_DIRECTORY');
        if ($public !== false && $public !== '') { self::outsidePublic($canonical, $public); }
        $target = $canonical . '/database-' . bin2hex(random_bytes(12));
        if (!mkdir($target, permissions: 0o700)) { throw new RuntimeException('Cannot reserve private synthetic database output'); }
        return $target;
    }

    private static function outsidePublic(string $root, string $public): void
    {
        $canonical = realpath($public);
        if ($canonical === false) { throw new RuntimeException('Required public helper sink is unavailable'); }
        $root = strtolower(str_replace('\\', replace: '/', subject: $root));
        $public = strtolower(str_replace('\\', replace: '/', subject: $canonical));
        if ($root === $public || str_starts_with($root, $public . '/')) {
            throw new RuntimeException('Private database output must be outside the public helper sink');
        }
    }
}
