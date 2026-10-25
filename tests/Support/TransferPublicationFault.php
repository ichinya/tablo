<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Armed only in an isolated ordinary Testo child, with real native filesystem publication. */
final class TransferPublicationFault
{
    private static string $mode = '';
    private static string $scratch = '';
    private static string $target = '';
    public const COMPETING_BYTES = 'synthetic-competing-target';

    public static function arm(string $mode, #[\SensitiveParameter] string $scratch, #[\SensitiveParameter] string $target): void
    { self::$mode = $mode; self::$scratch = $scratch; self::$target = $target; }

    public static function link(#[\SensitiveParameter] string $from, #[\SensitiveParameter] string $to): bool
    {
        if ($from === self::$scratch && $to === self::$target) {
            if (self::$mode === 'link-false') { return false; }
            if (self::$mode === 'competing') { file_put_contents($to, self::COMPETING_BYTES); }
        }
        return \link($from, $to);
    }

    public static function unlink(#[\SensitiveParameter] string $path): bool
    {
        if ($path === self::$scratch) {
            if (self::$mode === 'cleanup-false') { trigger_error('synthetic-private-cleanup-' . $path, E_USER_WARNING); return false; }
            if (self::$mode === 'cleanup-throw') { throw new \RuntimeException('synthetic-private-cleanup-' . $path); }
        }
        return \unlink($path);
    }
}
