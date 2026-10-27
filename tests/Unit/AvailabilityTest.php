<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use DateTimeImmutable;
use Tablo\AvailabilityBuckets;
use Tablo\AvailabilityGeometry;
use Tablo\AvailabilityPeriod;
use Tablo\AvailabilityPresenter;
use Tablo\AvailabilitySummary;
use Tablo\Tests\Support\AvailabilityFixtures as F;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\IncidentFixtures;
use Testo\Assert;
use Testo\Test;

final class AvailabilityTest
{
    #[Test]
    public function metricUsesOnlyKnownResultsAndRequiresTwo(): void
    {
        foreach ([[], [F::sample(1, null)], [F::sample(1, null), F::sample(2, 1, 0)]] as $index => $rows) {
            $summary = AvailabilitySummary::of($rows);
            Assert::same($summary['availability_label'], '—');
            Assert::same($summary['availability_state'], $index === 2 ? 'insufficient' : 'none');
            Assert::same($summary['total'], count($rows));
            Assert::same($summary['unknown'], $index === 0 ? 0 : 1);
        }
        $summary = AvailabilitySummary::of([F::sample(1, 1, 0), F::sample(2, 0, 20), F::sample(3, null, 999), F::sample(4, 1)]);
        Assert::same([$summary['online'], $summary['offline'], $summary['unknown'], $summary['known'], $summary['total']], [2, 1, 1, 3, 4]);
        Assert::same([$summary['availability_label'], $summary['known_label'], $summary['response_count'], $summary['known_without_time']], ['66,7%', '3 из 4', 2, 1]);
    }

    #[Test]
    public function endpointLabelsNeverRoundMixedResultsToCertainty(): void
    {
        foreach ([[0, 2, '0%'], [2, 2, '100%'], [1, 12_000, '<0,1%'], [11_999, 12_000, '>99,9%'],
            [1, 1000, '0,1%'], [999, 1000, '99,9%'], [1, 3, '33,3%']] as [$online, $known, $label]) {
            $rows = [];
            for ($id = 1; $id <= $known; $id++) { $rows[] = F::sample($id, $id <= $online ? 1 : 0); }
            Assert::same(AvailabilitySummary::of($rows)['availability_label'], $label);
        }
    }

    #[Test]
    public function rollingUtcPeriodsHaveExactHalfOpenBoundaries(): void
    {
        $now = new DateTimeImmutable('2026-03-01T05:00:00.123456+05:00');
        foreach (['24h' => [86_400, 720, '2026-02-28T00:00:00Z'], '7d' => [604_800, 5040, '2026-02-22T00:00:00Z'],
            '30d' => [2_592_000, 21_600, '2026-01-30T00:00:00Z']] as $value => [$seconds, $bucket, $from]) {
            $period = AvailabilityPeriod::select($value, $now);
            Assert::same([$period['seconds'], $period['bucket_seconds'], $period['from'], $period['to']], [$seconds, $bucket, $from, '2026-03-01T00:00:00Z']);
            Assert::same(array_column($period['options'], 'selected'), [$value === '24h', $value === '7d', $value === '30d']);
            $buckets = AvailabilityBuckets::of($period, []);
            Assert::same(count($buckets), 120);
            $first = FixtureValue::at($buckets, 0);
            $last = FixtureValue::at($buckets, 119);
            Assert::same([$first['from'], $last['to']], [$from, $period['to']]);
            for ($index = 1; $index < 120; $index++) {
                $current = FixtureValue::at($buckets, $index);
                $previous = FixtureValue::at($buckets, $index - 1);
                Assert::same($current['from'], $previous['to']);
            }
        }
        Assert::same(AvailabilityPeriod::select('24h', new DateTimeImmutable('2024-03-01T00:00:00Z'))['from'], '2024-02-29T00:00:00Z');
        foreach (['', '1d', [], null, 24] as $invalid) {
            Assert::true(IncidentFixtures::error(static fn () => AvailabilityPeriod::select($invalid)) instanceof \InvalidArgumentException);
        }
    }

    #[Test]
    public function bucketsKeepUnknownEmptyNullAndRealZeroDistinct(): void
    {
        $period = AvailabilityPeriod::select('24h', new DateTimeImmutable('2026-03-02T00:00:00Z'));
        $rows = [F::sample(1, 1, 0), F::sample(2, 0, 40), F::sample(3, 1), F::sample(4, null, 999),
            F::sample(5, null, null, '2026-03-01T00:12:00Z', 1), F::sample(6, 1, 10, '2026-03-01T23:59:59Z', 2)];
        $buckets = AvailabilityBuckets::of($period, $rows);
        $first = FixtureValue::at($buckets, 0);
        $second = FixtureValue::at($buckets, 1);
        $empty = FixtureValue::at($buckets, 2);
        $last = FixtureValue::at($buckets, 119);
        Assert::same([$first['total'], $first['online'], $first['offline'], $first['unknown'], $first['known_without_time']], [4, 2, 1, 1, 1]);
        Assert::same([$first['response_count'], $first['mean_ms'], $first['min_ms'], $first['max_ms']], [2, 20.0, 0, 40]);
        Assert::true($first['has_gap']); Assert::true(str_contains($first['gap_label'], 'Unknown: 1'));
        Assert::same([$second['total'], $second['mean_ms'], $second['mean_y']], [1, null, null]);
        Assert::same([$empty['total'], $empty['mean_ms'], $empty['gap_label']], [0, null, 'Нет проверок']);
        Assert::same([$last['total'], $last['mean_ms']], [1, 10.0]);
        Assert::same(array_sum(array_column($buckets, 'total')), 6);
        Assert::same(array_sum(array_column($buckets, 'response_count')), 3);
        Assert::same(AvailabilitySummary::of($rows)['revision_count'], 3);
        Assert::true(IncidentFixtures::error(static fn () => AvailabilityBuckets::of($period, [F::sample(7, 1, 2, $period['to'])])) instanceof \InvalidArgumentException);
    }

    #[Test]
    public function geometryIsFiniteBoundedAndDoesNotInventConnectingSegments(): void
    {
        $period = AvailabilityPeriod::select('24h', new DateTimeImmutable('2026-03-02T00:00:00Z'));
        foreach ([[F::sample(1, 1, 0)], [F::sample(1, 0, 2_147_483_647)], []] as $rows) {
            $view = AvailabilityPresenter::view(['site' => F::site(), 'rows' => $rows, 'overflow' => false], $period);
            Assert::same([$view['chart']['width'], $view['chart']['height']], [960, 300]);
            Assert::true($view['chart']['y_max'] >= 1);
            foreach ($view['buckets'] as $bucket) {
                Assert::true($bucket['x'] >= 64 && $bucket['x'] <= 944);
                if ($bucket['mean_y'] !== null) { Assert::true($bucket['mean_y'] >= 20 && $bucket['mean_y'] <= 252); }
            }
            Assert::false(array_key_exists('path', $view));
        }
        $zero = AvailabilityPresenter::view(['site' => F::site(), 'rows' => [F::sample(1, 1, 0)], 'overflow' => false], $period);
        $zeroBucket = FixtureValue::at($zero['buckets'], 0);
        Assert::same([$zeroBucket['mean_y'], $zeroBucket['mean_label']], [AvailabilityGeometry::BOTTOM, '0,0']);
        $overflow = AvailabilityPresenter::view(['site' => F::site(), 'rows' => [], 'overflow' => true], $period);
        Assert::same([$overflow['summary'], $overflow['buckets']], [null, []]);
    }
}
