<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Site from AvailabilityData
 * @phpstan-import-type Sample from AvailabilityData
 * @phpstan-import-type Read from AvailabilityData
 * @phpstan-import-type Period from AvailabilityPeriod
 * @phpstan-import-type Summary from AvailabilitySummary
 * @phpstan-import-type Geometry from AvailabilityGeometry
 * @phpstan-import-type Bucket from AvailabilityBucket
 * @phpstan-type View array{title:string,site:Site,period:Period,overflow:bool,summary:?Summary,chart:Geometry,buckets:list<Bucket>,overflow_message:string}
 */
final class AvailabilityPresenter
{
    /** @param Read $read
     * @param Period $period
     * @return View */
    public static function view(array $read, array $period): array
    {
        $buckets = $read['overflow'] ? [] : AvailabilityBuckets::of($period, $read['rows']);
        $maximum = 1;
        foreach ($buckets as $bucket) { $maximum = max($maximum, $bucket['max_ms'] ?? 0); }
        return ['title' => 'Доступность и время ответа', 'site' => $read['site'], 'period' => $period, 'overflow' => $read['overflow'],
            'summary' => $read['overflow'] ? null : AvailabilitySummary::of($read['rows']), 'chart' => AvailabilityGeometry::chart($period, $maximum),
            'buckets' => $buckets, 'overflow_message' => 'За период сохранено больше 12 000 проверок. Выберите более короткий период. Частичная статистика не показана.'];
    }
}
