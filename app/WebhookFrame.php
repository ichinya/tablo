<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookFrame
{
    public static function encode(#[\SensitiveParameter] string $endpoint,#[\SensitiveParameter] string $bearer,#[\SensitiveParameter] string $payload): ?string
    {
        if (strlen($endpoint)>500 || strlen($bearer)>512 || strlen($payload)>1024) { return null; }
        return pack('N3',strlen($endpoint),strlen($bearer),strlen($payload)).$endpoint.$bearer.$payload;
    }
}
