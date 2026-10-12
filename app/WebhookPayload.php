<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookPayload
{
    public static function eventId(#[\SensitiveParameter] mixed $object): string
    {
        if (!is_array($object) || !is_string($object['event_id'] ?? null)) { throw new \RuntimeException('Invalid frame.'); }
        return $object['event_id'];
    }

    public static function validate(#[\SensitiveParameter] string $payload, string $eventId, #[\SensitiveParameter] string $bearer): void
    {
        if (strlen($payload)>8192 || !preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D',$eventId)
            || ($bearer!=='' && !preg_match('/^[\x21-\x7e]{1,512}$/D',$bearer))) { throw new WebhookFailure('invalid-data'); }
    }

}
