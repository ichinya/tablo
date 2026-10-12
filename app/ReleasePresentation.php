<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Version from Presenter
 */
final class ReleasePresentation
{
    /**
     * @param Version $site
     */
    public static function apply(array &$site): void
    {
            $a = $site['deployed_version'];
            $b = $site['latest_release'];
            if ($a !== null && $b !== null) {
                $a = RegexReplacement::text(preg_replace('/^v(?=\d)/i', replacement: '', subject: $a));
                $b = RegexReplacement::text(preg_replace('/^v(?=\d)/i', replacement: '', subject: $b));
                if ($a === $b) {
                    $site['comparison'] = 'Актуальная версия';
                    $site['comparison_tone'] = 'green';
                    return;
                }
                if (preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $a) && preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $b)) {
                    $site['comparison'] = version_compare($a, $b, operator: '<') ? 'Доступно обновление' : 'Версия новее релиза';
                    $site['comparison_tone'] = 'amber';
                    return;
                }
                    $site['comparison'] = 'Версии различаются';
                    $site['comparison_tone'] = 'amber';
                return;
            }
            if ($site['checked_at']) {
                $site['comparison'] = 'Нет данных для сравнения';
            }
    }
}
