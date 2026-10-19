<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/**
 * @phpstan-type Event array{utc: string, stage: string, native_exit_code?: int, exit_code?: ?int, ...}
 * @phpstan-import-type Stream from SubprocessReceiptStreams
 * @phpstan-type Receipt array{pid: ?int, exit_code: ?int, root_exit_observed: bool, closed: bool, events: list<Event>, streams: array{stdout: Stream, stderr: Stream}, ownership: 'direct-child-only', platform?: string, signaled?: bool, term_signal?: ?int, proc_close?: ?int, ...}
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
        subprocess_receipt_termination_fields($value);
        $value['events'] = SubprocessReceiptEvents::checked($value['events'] ?? null);
        $value['streams'] = SubprocessReceiptStreams::checked($value['streams'] ?? null);
        if (($value['ownership'] ?? null) !== 'direct-child-only') { throw new \RuntimeException('Invalid test child ownership'); }
        /** @var Receipt $value */
        return $value;
    }
}


/** Old receipts with a known numeric exit remain valid; partially supplied new signal facts never become proof. */
function subprocess_receipt_termination_fields(array $value): void
{
    $fields = ['platform', 'signaled', 'term_signal', 'proc_close'];
    if (array_intersect($fields, array_keys($value)) === []) { return; }
    if (array_diff($fields, array_keys($value)) !== [] || !is_string($value['platform'] ?? null) || !is_bool($value['signaled'] ?? null)
        || ($value['term_signal'] !== null && !is_int($value['term_signal'])) || ($value['proc_close'] !== null && !is_int($value['proc_close']))) {
        throw new \RuntimeException('Invalid direct child signal observation.');
    }
    if ($value['signaled'] && (!is_int($value['term_signal']) || $value['term_signal'] < 1)) { throw new \RuntimeException('Unconfirmed direct child signal.'); }
    if (!$value['signaled'] && $value['term_signal'] !== null) { throw new \RuntimeException('Contradictory direct child signal.'); }
}
