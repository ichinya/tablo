<?php
declare(strict_types=1);

namespace Tablo;

final class IncidentPresenter
{
    public static function row(array $row, ?int $now = null): array
    {
        $now ??= time();
        $row['qualifiers'] = [];
        $row['status'] = match ($row['end_reason']) {
            'recovered' => 'Восстановление наблюдалось',
            'config-changed' => 'Прерван: конфигурация изменена',
            default => 'Продолжается; восстановление не наблюдалось',
        };
        if ($row['end_reason'] === null) {
            if (!$row['site_enabled']) { $row['qualifiers'][] = 'На паузе'; }
            if ($row['current_revision'] !== $row['config_revision']) { $row['qualifiers'][] = 'Конфигурация изменена; ожидается новая проверка'; }
            if ($now - strtotime($row['last_observed_at']) > 900) { $row['qualifiers'][] = 'Нет новых наблюдений'; }
        }
        if ($row['uncertain']) { $row['qualifiers'][] = 'Были неопределённые наблюдения'; }
        if ($row['clock_invalid']) { $row['qualifiers'][] = 'Нарушен порядок времени наблюдений'; }
        $row['interval_seconds'] = null;
        $row['interval_reason'] = $row['end_reason'] === 'config-changed' ? 'Инцидент прерван' : 'Восстановление не наблюдалось';
        if ($row['end_reason'] === 'recovered') {
            $interval = strtotime($row['recovered_at']) - strtotime($row['opened_at']);
            $row['interval_reason'] = $row['clock_invalid'] ? 'Нарушен порядок времени' : 'Точность времени — одна секунда';
            if ($interval > 0 && !$row['clock_invalid']) { $row['interval_seconds'] = $interval; }
        }
        $row['health_reason'] = $row['health_error_code'] === 'http' ? 'Ошибка HTTP'
            : (HttpFailure::MESSAGES[$row['health_error_code'] ?? ''] ?? 'Причина не указана');
        return $row;
    }
}
