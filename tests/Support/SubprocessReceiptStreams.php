<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** @phpstan-type Stream array{path: string, sha256: string, capture?: string, bytes?: null, errno?: null, ...} */
final class SubprocessReceiptStreams
{
    /** @return array{stdout: Stream, stderr: Stream} */
    public static function checked(mixed $value): array
    {
        if (!is_array($value)) { throw new \RuntimeException('Invalid direct test child streams'); }
        return ['stdout' => self::stream($value['stdout'] ?? null), 'stderr' => self::stream($value['stderr'] ?? null)];
    }

    /** @return Stream */
    private static function stream(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['path'] ?? null) || !is_string($value['sha256'] ?? null)) {
            throw new \RuntimeException('Invalid direct test child stream');
        }
        // The optional closed-reader metadata is emitted only by SubprocessReceipt::write.
        if (array_key_exists('capture', $value) && !is_string($value['capture'])) { throw new \RuntimeException('Invalid capture metadata'); }
        foreach (['bytes', 'errno'] as $key) {
            if (array_key_exists($key, $value) && $value[$key] !== null) { throw new \RuntimeException('Invalid closed-reader metadata'); }
        }
        /** @var Stream $value */
        return $value;
    }
}
