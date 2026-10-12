<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryHealthProjection
{
    /** @return array{'health_error_code': null|string, 'health_http_status': int|null, 'online': int|null, 'response_time_ms': int|null} */
    public static function project(array $state): array
    {
        return ['online' => HistoryScalar::integer($state['online'] ?? null, 0, 1),
            'health_error_code' => HistoryScalar::code($state['health_error_code'] ?? null, [...array_keys(HttpFailure::MESSAGES), 'http']),
            'health_http_status' => HistoryScalar::integer($state['health_http_status'] ?? null, 100, 599),
            'response_time_ms' => HistoryScalar::integer($state['response_time_ms'] ?? null, 0, 2_147_483_647)];
    }
}
