<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Version from Presenter
 * @phpstan-import-type View from Presenter
 */
final class FreshnessPresentation
{
    /**
     * @param Version $site
     * @return View
     */
    public static function apply(array &$site): array
    {
        $site['checked_label'] = $site['checked_at'] ? gmdate('d.m.Y H:i', PresentationTime::seconds($site['checked_at'])) . ' UTC' : 'Ещё не проверялся';
        $site['stale'] = $site['enabled'] && $site['checked_at'] && time() - PresentationTime::seconds($site['checked_at']) > 900;
        $site['tracks_version'] = ($site['version_path'] ?? '') !== '';
        if (!$site['tracks_version']) {
            $site['comparison'] = 'Версия сайта не отслеживается';
            $site['comparison_tone'] = 'muted';
            $site['deployed_label'] = '—';
        }
        $site['attention'] = $site['enabled'] && ($site['tone'] !== 'green'
            || ($site['tracks_version'] && $site['comparison_tone'] !== 'green') || $site['last_error'] || $site['stale']);
        return $site;
    }
}
