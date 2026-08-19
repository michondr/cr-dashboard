<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\Aggregator;
use PHPUnit\Framework\TestCase;

final class AggregatorTest extends TestCase
{
    public function testMean(): void
    {
        self::assertSame(6, Aggregator::mean([2, 4, 12]));
    }

    public function testMedianWithOddCount(): void
    {
        self::assertSame(4, Aggregator::median([12, 2, 4]));
    }

    public function testMedianWithEvenCountAveragesMiddle(): void
    {
        self::assertSame(4.5, Aggregator::median([12, 2, 5, 4]));
    }

    public function testMeanMedianSeriesLeavesEmptyBucketsNull(): void
    {
        $axis = [
            ['key' => '2026-08-01', 'start' => 1],
            ['key' => '2026-08-02', 'start' => 2],
        ];

        $series = Aggregator::meanMedian($axis, [1 => [10, 20]]);

        self::assertSame(['2026-08-01', '2026-08-02'], $series->buckets);
        self::assertSame([null, 15], $series->values);
        self::assertSame([null, 15], $series->median);
    }

    public function testCountsSeriesDefaultsMissingBucketsToZero(): void
    {
        $axis = [
            ['key' => '2026-08-01', 'start' => 1],
            ['key' => '2026-08-02', 'start' => 2],
        ];

        $series = Aggregator::counts($axis, [1 => [true, true, true]]);

        self::assertSame([0, 3], $series->values);
    }

    public function testValuesSeriesKeepsSnapshotNulls(): void
    {
        $axis = [
            ['key' => '2026-08-01', 'start' => 1],
            ['key' => '2026-08-02', 'start' => 2],
        ];

        $series = Aggregator::values($axis, ['2026-08-02' => 7]);

        self::assertSame([null, 7], $series->values);
    }
}
