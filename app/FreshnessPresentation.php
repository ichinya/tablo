<?php
declare(strict_types=1);

namespace Tablo;

final class FreshnessPresentation
{
    public static function apply(array &$site): void
    {
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
    }
}
