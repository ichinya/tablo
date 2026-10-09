<?php
declare(strict_types=1);

namespace Tablo;

final class Presenter
{
    public static function site(array $site): array
    {
        $site['host'] = parse_url($site['url'], PHP_URL_HOST) ?: $site['url'];
        $site['initial'] = mb_strtoupper(mb_substr($site['name'], 0, 1));
        $site['tone'] = 'muted';
        $site['status'] = 'Не проверен';
        $reason = $site['health_error_code'] ?? null;
        $site['health_reason_label'] = $reason === 'http' ? 'HTTP ' . ($site['health_http_status'] ?? '—')
            : (HttpFailure::MESSAGES[$reason ?? ''] ?? '');
        if (!$site['enabled']) {
            $site['status'] = 'На паузе';
        } elseif ($site['online'] !== null) {
            $site['tone'] = $site['online'] ? 'green' : 'red';
            $site['status'] = $site['online'] ? 'Online' : 'Offline';
        } elseif ($site['checked_at']) {
            $site['status'] = 'Нет данных';
        }
        $site['comparison_tone'] = 'muted';
        $site['comparison'] = 'Ожидает проверки';
        $site['deployed_label'] = $site['comparison_mode'] === 'release'
            ? ($site['deployed_version'] ?? '—') : ($site['deployed_commit'] ? substr($site['deployed_commit'], 0, 7) : '—');
        $site['latest_label'] = $site['comparison_mode'] === 'release'
            ? ($site['latest_release'] ?? '—') : ($site['latest_commit'] ? substr($site['latest_commit'], 0, 7) : '—');
        if ($site['comparison_mode'] === 'release') {
            $a = $site['deployed_version'];
            $b = $site['latest_release'];
            if ($a !== null && $b !== null) {
                $a = preg_replace('/^v(?=\d)/i', '', $a);
                $b = preg_replace('/^v(?=\d)/i', '', $b);
                if ($a === $b) {
                    $site['comparison'] = 'Актуальная версия';
                    $site['comparison_tone'] = 'green';
                } elseif (preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $a) && preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $b)) {
                    $site['comparison'] = version_compare($a, $b, '<') ? 'Доступно обновление' : 'Версия новее релиза';
                    $site['comparison_tone'] = 'amber';
                } else {
                    $site['comparison'] = 'Версии различаются';
                    $site['comparison_tone'] = 'amber';
                }
            } elseif ($site['checked_at']) {
                $site['comparison'] = 'Нет данных для сравнения';
            }
        } elseif ($site['deployed_commit'] && $site['latest_commit']) {
            $current = str_starts_with(strtolower($site['latest_commit']), strtolower($site['deployed_commit']));
            $site['comparison'] = $current ? 'Актуальный коммит' : 'Коммиты различаются';
            $site['comparison_tone'] = $current ? 'green' : 'amber';
        } elseif ($site['checked_at']) {
            $site['comparison'] = 'Нет данных для сравнения';
        }
        $site['checked_label'] = $site['checked_at'] ? gmdate('d.m.Y H:i', strtotime($site['checked_at'])) . ' UTC' : 'Ещё не проверялся';
        $site['stale'] = (bool) ($site['enabled'] && $site['checked_at'] && time() - strtotime($site['checked_at']) > 900);
        $site['tracks_version'] = ($site['version_path'] ?? '') !== '';
        if (!$site['tracks_version']) {
            $site['comparison'] = 'Версия сайта не отслеживается';
            $site['comparison_tone'] = 'muted';
            $site['deployed_label'] = '—';
        }
        $site['attention'] = (bool) ($site['enabled'] && ($site['tone'] !== 'green'
            || ($site['tracks_version'] && $site['comparison_tone'] !== 'green') || $site['last_error'] || $site['stale']));
        return $site;
    }
}
