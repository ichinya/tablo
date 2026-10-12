<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookAddress
{
    /** @return array{'host': non-empty-string, 'path'?: string, 'port'?: int<0, 65535>, 'query'?: string, 'scheme'?: non-empty-string} */
    public static function parts(#[\SensitiveParameter] string $url): array
    {
        if (strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\<>"{}|^]/', $url)) { self::invalid(); }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !(($parts['host'] ?? null) !== null)
            || (($parts['user'] ?? null) !== null) || (($parts['pass'] ?? null) !== null) || (($parts['fragment'] ?? null) !== null)) { self::invalid(); }
        WebhookHostSyntax::validate($parts);
        return $parts;
    }



    public static function invalid(): never
    {
        throw ValidationException::field(field: 'notifications', message: 'Требуется корректный адрес HTTPS длиной до 500 байт без учётных данных.');
    }
    public static function globallyRoutable(string $address): bool { return WebhookPublicAddress::globallyRoutable($address); }
}
