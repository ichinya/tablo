<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointHealthFailure
{
    public static function reason(#[\SensitiveParameter] \Throwable $error,#[\SensitiveParameter] array $state): string
    {
        if ($error instanceof HttpFailure) { return $error->reason; }
        return $state['health_http_status'] !== null ? 'invalid-response' : 'check-error';
    }
}
