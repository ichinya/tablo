<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** @phpstan-import-type Event from SubprocessReceiptData */
final class SubprocessReceiptEvents
{
    /** @return list<Event> */
    public static function checked(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \RuntimeException('Invalid direct test child events'); }
        $events = [];
        foreach (array_keys($value) as $index) { $events[] = self::event($value[$index] ?? null); }
        return $events;
    }

    /** @return Event */
    private static function event(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['utc'] ?? null) || !is_string($value['stage'] ?? null)
            || (array_key_exists('native_exit_code', $value) && !is_int($value['native_exit_code']))) {
            throw new \RuntimeException('Invalid direct test child event');
        }
        if (array_key_exists('exit_code', $value) && $value['exit_code'] !== null && !is_int($value['exit_code'])) {
            throw new \RuntimeException('Invalid direct test child exit observation');
        }
        /** @var Event $value */
        return $value;
    }
}
