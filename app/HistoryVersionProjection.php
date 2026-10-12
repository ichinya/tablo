<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryVersionProjection
{
    public static function deployed(array $state): array
    {
        return ['deployed_version' => HistoryVersionText::version($state['deployed_version'] ?? null),
            'deployed_commit' => HistoryVersionText::commit($state['deployed_commit'] ?? null)];
    }
    public static function diagnostics(array $state,string $versionStatus): array
    {
        return ['version_status' => $versionStatus,
            'version_error_code' => HistoryScalar::code($state['version_error_code'] ?? ($versionStatus === 'error' ? 'check-error' : null),
                [...array_keys(HttpFailure::MESSAGES), 'http', 'invalid-data']),
            'version_http_status' => HistoryScalar::integer($state['version_http_status'] ?? null, 100, 599)];
    }
}
