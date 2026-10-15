<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Snapshot from CheckHistorySnapshot */
final class NotificationComparison
{
    /**
     * @param Snapshot $row
     * @param array{comparison_mode: string, ...} $site
     */
    public static function comparison(array $row, #[\SensitiveParameter] array $site): string|false|null
    {
        if ($row['version_status'] !== 'ok') { return null; }
        if ($site['comparison_mode'] === 'release') {
            if ($row['release_error_code'] !== null) { return null; }
            $deployed=$row['deployed_version']; $latest=$row['latest_release'];
            if (!is_string($deployed) || !is_string($latest)) { return null; }
            foreach ([$deployed,$latest] as $version) {
                if (!preg_match('/^v?[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/D',$version)) { return null; }
            }
            return version_compare(ltrim($deployed,characters: 'v'),ltrim($latest,characters: 'v'),operator: '<') ? 'release_lower' : false;
        }
        if ($site['comparison_mode'] === 'branch') {
            if ($row['commit_error_code'] !== null || $row['deployed_commit'] === null || $row['latest_commit'] === null) { return null; }
            return str_starts_with($row['latest_commit'],$row['deployed_commit']) ? false : 'branch_difference';
        }
        return null;
    }
}
