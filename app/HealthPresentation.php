<?php
declare(strict_types=1);

namespace Tablo;

final class HealthPresentation
{
    public static function apply(array &$site): void
    {
        $site['tone'] = 'muted';
        $site['status'] = 'Не проверен';
        $reason = $site['health_error_code'] ?? null;
        $site['health_reason_label'] = $reason === 'http' ? 'HTTP ' . ($site['health_http_status'] ?? '—')
            : (HttpFailure::MESSAGES[$reason ?? ''] ?? '');
        if (!$site['enabled']) {
            $site['status'] = 'На паузе';
            return;
        }
        if ($site['online'] !== null) {
            $site['tone'] = $site['online'] ? 'green' : 'red';
            $site['status'] = $site['online'] ? 'Online' : 'Offline';
            return;
        }
        if ($site['checked_at']) {
            $site['status'] = 'Нет данных';
        }
    }
}
