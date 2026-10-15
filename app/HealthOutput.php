<?php
declare(strict_types=1);

namespace Tablo;

final class HealthOutput
{

    public static function project(#[\SensitiveParameter] array $state): array
    {
        $online = CheckScalar::integer($state['online'] ?? null, 0, 1);
        $reason = $state['health_error_code'] ?? null;
        $reason = $reason === null ? 'ok' : CheckScalar::choice($reason, [...array_keys(HttpFailure::MESSAGES), 'http']);
        return ['status' => match ($online) { 1 => 'online', 0 => 'offline', default => 'unknown' },
            'reason' => $reason, 'http_status' => CheckScalar::integer($state['health_http_status'] ?? null, 100, 599),
            'response_time_ms' => CheckScalar::integer($state['response_time_ms'] ?? null)];
    }

}
