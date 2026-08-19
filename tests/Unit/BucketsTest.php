<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\Buckets;
use PHPUnit\Framework\TestCase;

use function count;
use function strtotime;

final class BucketsTest extends TestCase
{
    public function testDayKeyIsUtcCalendarDay(): void
    {
        // 2026-08-04T09:30:00Z
        $epoch = strtotime('2026-08-04T09:30:00+00:00');

        self::assertSame('2026-08-04', Buckets::key((int) $epoch, Buckets::DAY));
    }

    public function testHourKeyIsWholeUtcHour(): void
    {
        $epoch = strtotime('2026-08-04T09:30:00+00:00');

        self::assertSame('2026-08-04 09:00', Buckets::key((int) $epoch, Buckets::HOUR));
    }

    public function testWeekKeyStartsOnMonday(): void
    {
        // Thursday 2026-08-06 -> week of Monday 2026-08-03
        $epoch = strtotime('2026-08-06T15:00:00+00:00');

        self::assertSame('2026-08-03', Buckets::key((int) $epoch, Buckets::WEEK));
    }

    public function testWeekKeyForSundayIsPreviousMonday(): void
    {
        $epoch = strtotime('2026-08-09T15:00:00+00:00');

        self::assertSame('2026-08-03', Buckets::key((int) $epoch, Buckets::WEEK));
    }

    public function testWeekKeyForMondayIsItself(): void
    {
        $epoch = strtotime('2026-08-03T15:00:00+00:00');

        self::assertSame('2026-08-03', Buckets::key((int) $epoch, Buckets::WEEK));
    }

    public function testDayAxisCoversTheWindow(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $axis = Buckets::axis($now, Buckets::DAY, 10);

        self::assertSame('2026-07-31', $axis[0]['key']);
        self::assertSame('2026-08-10', $axis[count($axis) - 1]['key']);
        self::assertSame(11, count($axis));
    }

    public function testHourAxisAtExactBoundaryHasOneBucket(): void
    {
        $now = (int) strtotime('2026-08-10T05:00:00+00:00');
        $axis = Buckets::axis($now, Buckets::HOUR, 0);

        self::assertSame(1, count($axis));
        self::assertSame('2026-08-10 05:00', $axis[0]['key']);
    }
}
