<?php
declare(strict_types=1);

namespace Tablo;

final class Presenter
{
    public static function site(array $site): array
    {
        $host = parse_url($site['url'], PHP_URL_HOST);
        $site['host'] = $host ? $host : $site['url'];
        $site['initial'] = mb_strtoupper(mb_substr($site['name'], start: 0, length: 1));
        $site['tone'] = 'muted';
        $site['status'] = match (true) {
            !$site['enabled'] => 'На паузе',
            $site['online'] !== null => $site['online'] ? 'Online' : 'Offline',
            (bool) $site['checked_at'] => 'Нет данных',
            default => 'Не проверен',
        };
        if ($site['enabled'] && $site['online'] !== null) {
            $site['tone'] = $site['online'] ? 'green' : 'red';
        }
        $site['comparison_tone'] = 'muted';
        $site['comparison'] = $site['checked_at'] ? 'Нет данных для сравнения' : 'Ожидает проверки';
        $site['deployed_label'] = $site['deployed_commit'] ? substr($site['deployed_commit'], offset: 0, length: 7) : '—';
        $site['latest_label'] = $site['latest_commit'] ? substr($site['latest_commit'], offset: 0, length: 7) : '—';
        if ($site['comparison_mode'] === 'release') {
            $site['deployed_label'] = $site['deployed_version'] ?? '—';
            $site['latest_label'] = $site['latest_release'] ?? '—';
            $a = $site['deployed_version'];
            $b = $site['latest_release'];
            if ($a !== null && $b !== null) {
                $a = preg_replace('/^v(?=\d)/i', replacement: '', subject: $a);
                $b = preg_replace('/^v(?=\d)/i', replacement: '', subject: $b);
                $site['comparison'] = match (true) {
                    $a === $b => 'Актуальная версия',
                    (bool) (preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $a) && preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $b))
                        => version_compare($a, $b, operator: '<') ? 'Доступно обновление' : 'Версия новее релиза',
                    default => 'Версии различаются',
                };
                $site['comparison_tone'] = $a === $b ? 'green' : 'amber';
            }
        }
        if ($site['comparison_mode'] !== 'release' && $site['deployed_commit'] && $site['latest_commit']) {
            $current = str_starts_with(strtolower($site['latest_commit']), strtolower($site['deployed_commit']));
            $site['comparison'] = $current ? 'Актуальный коммит' : 'Коммиты различаются';
            $site['comparison_tone'] = $current ? 'green' : 'amber';
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
