<?php
declare(strict_types=1);

namespace Tablo;

final class BranchPresentation
{
    public static function apply(array &$site): void
    {
        if ($site['deployed_commit'] && $site['latest_commit']) {
            $current = str_starts_with(strtolower($site['latest_commit']), strtolower($site['deployed_commit']));
            $site['comparison'] = $current ? 'Актуальный коммит' : 'Коммиты различаются';
            $site['comparison_tone'] = $current ? 'green' : 'amber';
            return;
        }
        if ($site['checked_at']) {
            $site['comparison'] = 'Нет данных для сравнения';
        }
    }
}
