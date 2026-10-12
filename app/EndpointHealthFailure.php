<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 */
final class EndpointHealthFailure
{
    /**
     * @param CheckResult $state
     */
    public static function reason(#[\SensitiveParameter] \Throwable $error,#[\SensitiveParameter] array $state): string
    {
        if ($error instanceof HttpFailure) { return $error->reason; }
        return $state['health_http_status'] !== null ? 'invalid-response' : 'check-error';
    }
}
