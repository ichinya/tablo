<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type View array{message: string, status: string, stale: bool, enabled: bool, visible: bool, branch: string, installed: string, latest: string, successful_at: string, new_commits: ?int, compare_url: string, interval_minutes: int} */
// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SelfUpdatePresentation
{
    /** @return View */
    public static function saved(SelfUpdateRepository $repository, ?BuildMetadata $build = null, ?int $now = null): array
    { return self_update_presentation($repository, $build, $now); }
}

/** @return \Tablo\SelfUpdatePresentation::View */
function self_update_presentation(SelfUpdateRepository $repository, ?BuildMetadata $build, ?int $now): array
{
        $build ??= BuildMetadata::configured(); $now ??= time(); $row = $repository->get();
        $bound = $row['build_fingerprint'] === $build->fingerprint();
        $status = $bound && $build->valid() ? SqlColumn::string($row, 'status') : 'no_data';
        $success = $bound ? $row['successful_at'] : null;
        $latest = $bound && is_string($row['latest_sha']) ? $row['latest_sha'] : '';
        $enabled = $row['enabled'] === 1;
        $stale = $success !== null && ($row['error_code'] !== null || $row['next_due'] <= $now || !$enabled);
        $message = self_update_message($build, $status);
        if ($bound && $row['error_code'] !== null) { $message .= ' Не удалось проверить обновления.'; }
        return ['message' => $message, 'status' => $status, 'stale' => $stale, 'enabled' => $enabled,
            'visible' => $status !== 'current' || $stale, 'branch' => $build->valid() ? $build->branch : '',
            'installed' => $build->valid() ? substr($build->sha, offset: 0, length: 12) : '', 'latest' => substr($latest, offset: 0, length: 12),
            'successful_at' => is_int($success) ? gmdate('Y-m-d H:i:s', $success) . ' UTC' : '',
            'new_commits' => $status === 'behind' ? $row['new_commits'] : null,
            'compare_url' => self_update_compare_url($build, $latest, $status),
            'interval_minutes' => $row['interval_minutes']];
    }

function self_update_message(BuildMetadata $build, string $status): string
{
    return match ($status) {
        'behind' => 'В ветке ' . $build->branch . ' доступны новые коммиты. Обновите Tablo.',
        'current' => 'Установленная сборка соответствует ветке.',
        'ahead' => 'Установленная сборка впереди отслеживаемой ветки.',
        'diverged' => 'История установленной сборки и ветки разошлась.',
        'force_pushed' => 'История отслеживаемой ветки переписана. Проверьте изменения вручную.',
        'unknown' => 'Не удалось установить связь коммитов. Установленный или ранее наблюдавшийся SHA недоступен.',
        default => $build->valid() ? 'Нет данных о состоянии ветки.' : 'Нет данных: метаданные установленной сборки отсутствуют или некорректны.',
    };
}

function self_update_compare_url(BuildMetadata $build, string $latest, string $status): string
{
    return $build->valid() && preg_match('/\A[0-9a-f]{40}\z/D', $latest) === 1 && $status !== 'unknown'
        ? 'https://github.com/ichinya/tablo/compare/' . $build->sha . '...' . $latest : '';
}
