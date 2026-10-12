<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;
use Throwable;

final class FixtureFailureEvidence
{
    public static function record(string $path, string $kind, Throwable $error): void
    {
        // Exception messages/arguments can contain passwords or private database rows.
        set_error_handler(static fn(): bool => true);
        try {
            file_put_contents($path . '/failure-' . bin2hex(random_bytes(8)) . '.json',
                json_encode(['utc' => gmdate('c'), 'kind' => $kind, 'type' => $error::class]) . "\n", LOCK_EX);
        } finally { restore_error_handler(); }
    }
}
