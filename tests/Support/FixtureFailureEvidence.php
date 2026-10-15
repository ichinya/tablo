<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
use Throwable;

final class FixtureFailureEvidence
{
    /** @return array{utc: string, kind: string, type: string, ...} */
    public static function read(string $path): array
    {
        return self::checked(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($path)), associative: true, depth: 32, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array{utc: string, kind: string, type: string, ...} */
    private static function checked(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['utc'] ?? null) || !is_string($value['kind'] ?? null) || !is_string($value['type'] ?? null)) {
            throw new \RuntimeException('Invalid retained fixture failure record');
        }
        return $value;
    }

    public static function record(string $path, string $kind, Throwable $error): void
    {
        // Exception messages/arguments can contain passwords or private database rows.
        set_error_handler(static fn(): bool => true);
        try {
            $encoded = json_encode(['utc' => gmdate('c'), 'kind' => $kind, 'type' => $error::class]);
            file_put_contents($path . '/failure-' . bin2hex(random_bytes(8)) . '.json',
                ($encoded === false ? '' : $encoded) . "\n", LOCK_EX);
        } finally { restore_error_handler(); }
    }
}
