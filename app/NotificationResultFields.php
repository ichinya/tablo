<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationResultFields
{
    public static function code(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['sent','http','redirect','timeout','dns','ssrf','tls','refused','network','size',
            'invalid-url','invalid-data','invalid-response','ambiguous','child-start','child-frame'], strict: true)) {
            throw new \InvalidArgumentException('Invalid notification result.');
        }
        return $value;
    }
    public static function status(mixed $value): ?int
    {
        if ($value === null) { return null; }
        if (!is_int($value) || $value < 100 || $value > 599) { throw new \InvalidArgumentException('Invalid notification result.'); }
        return $value;
    }
}
