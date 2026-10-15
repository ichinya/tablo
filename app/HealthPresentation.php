<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Header from Presenter
 * @phpstan-import-type Health from Presenter
 */
final class HealthPresentation
{
    /**
     * @param Header $site
     * @return Health
     */
    public static function apply(array &$site): array
    {
        $site['tone'] = 'muted';
        $site['status'] = 'Не проверен';
        $reason = $site['health_error_code'] ?? null;
        $site['health_reason_label'] = $reason === 'http' ? 'HTTP ' . ($site['health_http_status'] ?? '—')
            : (HttpFailure::MESSAGES[$reason ?? ''] ?? '');
        if (!$site['enabled']) {
            $site['status'] = 'На паузе';
            return $site;
        }
        if ($site['online'] !== null) {
            $site['tone'] = $site['online'] ? 'green' : 'red';
            $site['status'] = $site['online'] ? 'Online' : 'Offline';
            return $site;
        }
        if ($site['checked_at']) {
            $site['status'] = 'Нет данных';
        }
        return $site;
    }
}
