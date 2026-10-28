<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Sample from AvailabilityData
 * @phpstan-type Bucket array{index:int,from:string,to:string,label:string,total:int,online:int,offline:int,unknown:int,known_without_time:int,response_count:int,mean_ms:?float,min_ms:?int,max_ms:?int,mean_label:string,range_label:string,gap_label:string,x:int,mean_y:?int,has_gap:bool}
 */
final class AvailabilityBucket
{
    /** @param list<Sample> $rows
     * @return Bucket */
    public static function present(int $index, \DateTimeImmutable $from, \DateTimeImmutable $to, array $rows, int $maximum): array
    {
        $summary = AvailabilitySummary::of($rows); $times = [];
        foreach ($rows as $row) {
            if ($row['online'] === null || $row['response_time_ms'] === null) { continue; }
            $times[] = $row['response_time_ms'];
        }
        $mean = $times === [] ? null : (float) (array_sum($times) / count($times));
        $minimum = $times === [] ? null : min($times); $maxTime = $times === [] ? null : max($times);
        $gap = self::gap($summary['total'], $summary['unknown'], $summary['known_without_time']);
        return ['index' => $index, 'from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'label' => $from->format('d.m H:i') . ' — ' . $to->format('d.m H:i'), 'total' => $summary['total'],
            'online' => $summary['online'], 'offline' => $summary['offline'], 'unknown' => $summary['unknown'],
            'known_without_time' => $summary['known_without_time'], 'response_count' => count($times),
            'mean_ms' => $mean, 'min_ms' => $minimum, 'max_ms' => $maxTime,
            'mean_label' => $mean === null ? '—' : AvailabilityGeometry::milliseconds($mean),
            'range_label' => $minimum === null || $maxTime === null ? '—' : $minimum . ' — ' . $maxTime,
            'gap_label' => $gap, 'x' => AvailabilityGeometry::x($index),
            'mean_y' => $mean === null ? null : AvailabilityGeometry::y($mean, $maximum), 'has_gap' => $gap !== ''];
    }

    private static function gap(int $total, int $unknown, int $missing): string
    {
        if ($total === 0) { return 'Нет проверок'; }
        $parts = [];
        if ($unknown > 0) { $parts[] = 'Unknown: ' . $unknown; }
        if ($missing > 0) { $parts[] = 'Нет времени ответа: ' . $missing; }
        return implode('; ', $parts);
    }
}
