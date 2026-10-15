<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type Result array{code: string, http_status?: ?int, ...}
 * @phpstan-import-type Attempt from WebhookSupervisor
 */
final class NotificationResult
{
    /**
     * @param Attempt $result
     * @return array{code: string, http_status?: ?int, elapsed_ms?: int}
     */
    public static function publicFields(array $result): array
    {
        $public = ['code' => $result['code']];
        if (array_key_exists('http_status', $result)) { $public['http_status'] = $result['http_status']; }
        if (array_key_exists('elapsed_ms', $result)) { $public['elapsed_ms'] = $result['elapsed_ms']; }
        return $public;
    }

    public static function validate(array $result): void
    {
        self::checked($result);
    }

    /** @return Result */
    public static function checked(array $result): array
    {
        $code = NotificationResultFields::code($result['code'] ?? null);
        $status = NotificationResultFields::status($result['http_status'] ?? null);
        // Both required/optional primitive fields were checked without coercion; retain every original field.
        /** @var Result $validated */
        $validated = $result;
        if ($code==='sent' && ($status === null || $status<200 || $status>=300)) {
            throw new \InvalidArgumentException('Invalid notification result.');
        }
        return $validated;
    }

}
