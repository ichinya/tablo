<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Health from Presenter
 * @phpstan-import-type Version from Presenter
 */
final class VersionPresentation
{
    /**
     * @param Health $site
     * @return Version
     */
    public static function apply(array &$site): array
    {
        $site['comparison_tone'] = 'muted';
        $site['comparison'] = 'Ожидает проверки';
        $site['deployed_label'] = $site['comparison_mode'] === 'release'
            ? ($site['deployed_version'] ?? '—') : self::commit($site['deployed_commit']);
        $site['latest_label'] = $site['comparison_mode'] === 'release'
            ? ($site['latest_release'] ?? '—') : self::commit($site['latest_commit']);
        if ($site['comparison_mode'] === 'release') { ReleasePresentation::apply($site); return $site; }
        BranchPresentation::apply($site);
        return $site;
    }
    private static function commit(?string $commit): string
    { return $commit ? substr($commit,offset: 0,length: 7) : '—'; }
}
