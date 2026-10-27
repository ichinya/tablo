<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Sample from AvailabilityData
 * @phpstan-import-type Period from AvailabilityPeriod
 * @phpstan-import-type Bucket from AvailabilityBucket
 */
final class AvailabilityBuckets
{
    /** @param Period $period
     * @param list<Sample> $rows
     * @return list<Bucket> */
    public static function of(array $period, array $rows): array
    {
        $start = new \DateTimeImmutable($period['from']); $epoch = $start->getTimestamp();
        $groups = array_fill(0, count: 120, value: []);
        $maximum = 1;
        foreach ($rows as $row) {
            $offset = (new \DateTimeImmutable($row['checked_at']))->getTimestamp() - $epoch;
            if ($offset < 0 || $offset >= $period['seconds']) { throw new \InvalidArgumentException('Availability sample outside period.'); }
            $index = intdiv($offset, $period['bucket_seconds']);
            $groups[$index][] = $row;
            if ($row['online'] !== null && $row['response_time_ms'] !== null) { $maximum = max($maximum, $row['response_time_ms']); }
        }
        $buckets = [];
        for ($index = 0; $index < 120; $index++) {
            $from = $start->add(new \DateInterval('PT' . ($index * $period['bucket_seconds']) . 'S'));
            $to = $from->add(new \DateInterval('PT' . $period['bucket_seconds'] . 'S'));
            $buckets[] = AvailabilityBucket::present($index, $from, $to, $groups[$index] ?? [], $maximum);
        }
        return $buckets;
    }

}
