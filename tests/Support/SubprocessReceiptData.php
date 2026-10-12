<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/**
 * @phpstan-type Event array{utc: string, stage: string, native_exit_code?: int, exit_code?: ?int, ...}
 * @phpstan-import-type Stream from SubprocessReceiptStreams
 * @phpstan-type Receipt array{pid: ?int, exit_code: ?int, root_exit_observed: bool, closed: bool, events: list<Event>, streams: array{stdout: Stream, stderr: Stream}, ownership: 'direct-child-only', ...}
 */
final class SubprocessReceiptData
{
    /** @return Receipt */
    public static function checked(mixed $value): array
    {
        if (!is_array($value) || !array_key_exists('pid', $value) || !array_key_exists('exit_code', $value)
            || ($value['pid'] !== null && !is_int($value['pid'])) || ($value['exit_code'] !== null && !is_int($value['exit_code']))
            || !is_bool($value['closed'] ?? null) || !is_bool($value['root_exit_observed'] ?? null)) {
            throw new \RuntimeException('Invalid direct test child receipt');
        }
        $value['events'] = SubprocessReceiptEvents::checked($value['events'] ?? null);
        $value['streams'] = SubprocessReceiptStreams::checked($value['streams'] ?? null);
        if (($value['ownership'] ?? null) !== 'direct-child-only') { throw new \RuntimeException('Invalid test child ownership'); }
        /** @var Receipt $value */
        return $value;
    }
}
