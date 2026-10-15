<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

/** Writes only explicitly selected synthetic case files; never walks a fixture or TEMP. */
final class PasswordHelperEvidenceFiles
{
    public static function bytes(string $path, string $bytes): void
    {
        $file = fopen($path, mode: 'x');
        if ($file === false) { throw new RuntimeException('Cannot reserve synthetic helper evidence'); }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes)) { throw new RuntimeException('Incomplete synthetic helper evidence'); }
        } finally { fclose($file); }
        chmod($path, permissions: 0o600);
    }

    /** @param array<string, mixed> $record */
    public static function record(string $path, array $record): void
    { self::bytes($path, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); }

    /** @return array{path: string, bytes: int, sha256: string} */
    public static function copy(string $source, string $destination): array
    {
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($source));
        self::bytes($destination, $bytes);
        return ['path' => $destination, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    /** @param list<string> $paths
     * @return array<string, ?string> */
    public static function pins(array $paths): array
    {
        $pins = [];
        foreach ($paths as $path) { $pins[$path] = is_file($path) ? \Tablo\RequiredFile::digest($path) : null; }
        return $pins;
    }

    /** Redact before any summary truncation, including secrets beyond byte 2048.
     * @param list<string> $secrets */
    public static function summary(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] array $secrets): string
    {
        $redacted = str_replace($secrets, replace: '[synthetic input redacted]', subject: $bytes);
        return preg_replace('/\$(?:2[aby]|argon2id|argon2i)\$[^\s"<>]+/', replacement: '[hash redacted]', subject: $redacted)
            ?? throw new RuntimeException('Cannot redact synthetic helper summary');
    }
}
