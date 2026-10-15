<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookHostSyntax
{
    public static function validate(#[\SensitiveParameter] array $parts): void
    {
        $host = trim($parts['host'], characters: '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (strlen($host) > 253 || !str_contains($host,'.') || str_ends_with($host,'.')
                || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD',$host) !== 1) { WebhookAddress::invalid(); }
        }
    }
}
