<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

/** Test-only stream: deterministic source I/O faults and one close per opened reader. */
final class EngineSchemaDescriptorStream
{
    public mixed $context = null;
    public static string $bytes = '';
    public static int $position = 0;
    public static int $opens = 0;
    public static int $closes = 0;
    public static ?int $failAt = null;
    public static bool $failClose = false;
    public static bool $failOpen = false;

    public function stream_open(string $path, string $mode, int $options, mixed &$openedPath): bool
    {
        self::$opens++;
        if (self::$failOpen) { trigger_error('descriptor-private-open-sentinel', E_USER_WARNING); return false; }
        return true;
    }
    public function stream_read(int $count): string|false
    {
        if (self::$failAt !== null && self::$position >= self::$failAt) { trigger_error('descriptor-private-read-sentinel', E_USER_WARNING); return false; }
        $limit = self::$failAt === null ? $count : min($count, self::$failAt - self::$position);
        $bytes = substr(self::$bytes, self::$position, $limit); self::$position += strlen($bytes); return $bytes;
    }
    public function stream_eof(): bool { return self::$failAt === null && self::$position >= strlen(self::$bytes); }
    public function stream_stat(): array { return []; }
    public function stream_close(): void
    {
        self::$closes++;
        if (self::$failClose) { throw new \RuntimeException('descriptor-private-close-sentinel'); }
    }
}

