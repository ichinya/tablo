<?php
declare(strict_types=1);

namespace Tablo;

final class CurlWebhookOptions
{
    public static function build(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] array $headers, array $pin, int $remaining, WebhookResponseBudget $buffer): array
    {
            return [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>$pin,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining),CURLOPT_TIMEOUT_MS=>min(6000,$remaining),
                CURLOPT_USERAGENT=>'Tablo/0.1',
                CURLOPT_HEADERFUNCTION => static fn (#[\SensitiveParameter] $handle, #[\SensitiveParameter] string $line): int => $buffer->header($line),
                CURLOPT_WRITEFUNCTION => static fn (#[\SensitiveParameter] $handle, #[\SensitiveParameter] string $chunk): int => $buffer->write($chunk),
];

    }

}
